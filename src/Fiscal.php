<?php

declare(strict_types=1);

/**
 * Módulo fiscal SAT — banco CacaoPay.
 *
 * Implementa el contrato docs/fiscal-sat.md:
 *  - identidad fiscal del emisor (fiscal_settings),
 *  - CFDI como registro interno simulado (cfdi_invoices),
 *  - balance de facturación y congruencia de depósitos/retiros (STP),
 *  - cierres de periodo inmutables encadenados con SHA-256 (fiscal_periods),
 *  - exportación CSV.
 *
 * Compatibilidad: PHP 8.1+, MySQL 5.7+/8.x/MariaDB (sin CTE, sin JSON_TABLE).
 * Zona horaria de negocio: America/Mexico_City.
 */
final class Fiscal
{
    private const TZ = 'America/Mexico_City';

    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** RFC mexicano persona física/moral (mismo regex del contrato §3.3). */
    private const RFC_REGEX = '/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/';

    /** RFC genéricos aceptados (§3.3). */
    private const GENERIC_RFCS = ['XAXX010101000', 'XEXX010101000'];

    private const MESES = [
        1 => 'enero',
        2 => 'febrero',
        3 => 'marzo',
        4 => 'abril',
        5 => 'mayo',
        6 => 'junio',
        7 => 'julio',
        8 => 'agosto',
        9 => 'septiembre',
        10 => 'octubre',
        11 => 'noviembre',
        12 => 'diciembre',
    ];

    /** Etiquetas de forma de pago SAT (catálogo c_FormaPago). */
    private const FORMA_PAGO_LABELS = [
        '01' => 'Efectivo',
        '02' => 'Cheque nominativo',
        '03' => 'Transferencia electrónica de fondos',
        '04' => 'Tarjeta de crédito',
        '05' => 'Monedero electrónico',
        '06' => 'Dinero electrónico',
        '08' => 'Vales de despensa',
        '12' => 'Dación en pago',
        '13' => 'Pago por subrogación',
        '14' => 'Pago por consignación',
        '15' => 'Condonación',
        '17' => 'Compensación',
        '23' => 'Novación',
        '24' => 'Confusión',
        '25' => 'Remisión de deuda',
        '26' => 'Prescripción o caducidad',
        '27' => 'A satisfacción del acreedor',
        '28' => 'Tarjeta de débito',
        '29' => 'Tarjeta de servicios',
        '30' => 'Aplicación de anticipos',
        '31' => 'Intermediario pagos',
        '99' => 'Otros',
    ];

    private const CFDI_EXPORT_HEADERS = [
        'UUID_FISCAL', 'TIPO', 'ESTATUS', 'FECHA_EMISION', 'SERIE', 'FOLIO',
        'RFC_EMISOR', 'RAZON_SOCIAL_EMISOR', 'RFC_RECEPTOR', 'NOMBRE_RECEPTOR',
        'REGIMEN_RECEPTOR', 'USO_CFDI', 'METODO_PAGO', 'FORMA_PAGO', 'MONEDA',
        'SUBTOTAL', 'IVA', 'TOTAL', 'UUID_RELACIONADO', 'REFERENCIA_DEPOSITO',
        'MOTIVO_CANCELACION',
    ];

    private const MOVIMIENTOS_EXPORT_HEADERS = [
        'FECHA', 'DIRECCION', 'MONTO_MXN', 'MONTO_CACAO', 'CLABE', 'BANCO',
        'BENEFICIARIO', 'REFERENCIA', 'ID_TRANSFERENCIA', 'ESTATUS_FISCAL', 'UUID_CFDI',
    ];

    private const CONGRUENCIA_EXPORT_HEADERS = [
        'FECHA', 'CONCEPTO', 'TIPO', 'MONTO', 'FACTURADO', 'DIFERENCIA',
        'CFDI_UUID', 'ESTADO', 'OBSERVACION',
    ];

    // ------------------------------------------------------------------
    // Instalación
    // ------------------------------------------------------------------

    /**
     * Crea la fila única de configuración fiscal (id=1) si no existe.
     * Los valores iniciales salen del .env (FISCAL_*). Usado por install.php.
     */
    public static function ensureSettings(): void
    {
        $pdo = Database::pdo();
        $check = $pdo->query('SELECT id FROM fiscal_settings WHERE id = 1');
        if ($check !== false && $check->fetchColumn() !== false) {
            return;
        }

        $rfc = strtoupper(self::scalarString(Config::get('FISCAL_RFC')));
        if ($rfc !== '' && !self::isValidRfc($rfc)) {
            $rfc = '';
        }

        $razon = trim(Config::get('FISCAL_RAZON_SOCIAL'));
        if (mb_strlen($razon) > 255) {
            $razon = mb_substr($razon, 0, 255);
        }

        $regimen = trim(Config::get('FISCAL_REGIMEN', '612'));
        if (!preg_match('/^\d{3}$/', $regimen)) {
            $regimen = '612';
        }

        $cp = trim(Config::get('FISCAL_CP'));
        if ($cp !== '' && !preg_match('/^\d{5}$/', $cp)) {
            $cp = '';
        }

        $serie = trim(Config::get('FISCAL_SERIE', 'A'));
        if ($serie === '') {
            $serie = 'A';
        }
        if (mb_strlen($serie) > 10) {
            $serie = mb_substr($serie, 0, 10);
        }

        $ivaRateRaw = trim(Config::get('FISCAL_IVA_RATE', '0.16'));
        $ivaRate = is_numeric($ivaRateRaw) ? (float) $ivaRateRaw : 0.16;
        if ($ivaRate < 0 || $ivaRate > 1) {
            $ivaRate = 0.16;
        }

        $moneda = strtoupper(trim(Config::get('FISCAL_MONEDA', 'MXN')));
        if (!preg_match('/^[A-Z]{3}$/', $moneda)) {
            $moneda = 'MXN';
        }

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO fiscal_settings
                (id, rfc_emisor, razon_social, regimen_fiscal, codigo_postal, serie, iva_rate, moneda)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $rfc,
            $razon,
            $regimen,
            $cp,
            $serie,
            number_format($ivaRate, 4, '.', ''),
            $moneda,
        ]);
    }

    // ------------------------------------------------------------------
    // Configuración fiscal (§3.5)
    // ------------------------------------------------------------------

    public static function settings(array $user): never
    {
        $row = self::settingsRow();
        Response::json([
            'settings' => self::settingsPayload($row),
            'configured' => self::isConfigured($row),
        ]);
    }

    public static function updateSettings(array $user, array $body): never
    {
        Auth::requireRole($user, ['ADMIN']);

        $sets = [];
        $params = [];

        if (array_key_exists('rfcEmisor', $body)) {
            $rfc = strtoupper(self::scalarString($body['rfcEmisor']));
            if ($rfc !== '' && !self::isValidRfc($rfc)) {
                throw new AppException(400, 'RFC inválido');
            }
            $sets[] = 'rfc_emisor = ?';
            $params[] = $rfc;
        }

        if (array_key_exists('razonSocial', $body)) {
            $razon = self::scalarString($body['razonSocial']);
            if (mb_strlen($razon) > 255) {
                throw new AppException(400, 'Razón social inválida (máximo 255 caracteres)');
            }
            $sets[] = 'razon_social = ?';
            $params[] = $razon;
        }

        if (array_key_exists('regimenFiscal', $body)) {
            $regimen = self::scalarString($body['regimenFiscal']);
            if (!preg_match('/^\d{3}$/', $regimen)) {
                throw new AppException(400, 'Régimen fiscal inválido (3 dígitos)');
            }
            $sets[] = 'regimen_fiscal = ?';
            $params[] = $regimen;
        }

        if (array_key_exists('codigoPostal', $body)) {
            $cp = self::scalarString($body['codigoPostal']);
            if ($cp !== '' && !preg_match('/^\d{5}$/', $cp)) {
                throw new AppException(400, 'Código postal inválido (5 dígitos)');
            }
            $sets[] = 'codigo_postal = ?';
            $params[] = $cp;
        }

        if (array_key_exists('serie', $body)) {
            $serie = self::scalarString($body['serie']);
            if (mb_strlen($serie) > 10) {
                throw new AppException(400, 'Serie inválida (máximo 10 caracteres)');
            }
            $sets[] = 'serie = ?';
            $params[] = $serie;
        }

        if (array_key_exists('ivaRate', $body)) {
            $raw = $body['ivaRate'];
            if (!is_numeric($raw)) {
                throw new AppException(400, 'Tasa de IVA inválida');
            }
            $rate = (float) $raw;
            if ($rate < 0 || $rate > 1) {
                throw new AppException(400, 'Tasa de IVA inválida');
            }
            $sets[] = 'iva_rate = ?';
            $params[] = number_format($rate, 4, '.', '');
        }

        if (array_key_exists('moneda', $body)) {
            $moneda = strtoupper(self::scalarString($body['moneda']));
            if (!preg_match('/^[A-Z]{3}$/', $moneda)) {
                throw new AppException(400, 'Moneda inválida (3 letras)');
            }
            $sets[] = 'moneda = ?';
            $params[] = $moneda;
        }

        $pdo = Database::pdo();
        if ($sets !== []) {
            $stmt = $pdo->prepare('UPDATE fiscal_settings SET ' . implode(', ', $sets) . ' WHERE id = 1');
            $stmt->execute($params);
        }

        $row = self::settingsRow();
        Response::json([
            'settings' => self::settingsPayload($row),
            'configured' => self::isConfigured($row),
        ]);
    }

    // ------------------------------------------------------------------
    // Balance de facturación (§3.8, §4.1)
    // ------------------------------------------------------------------

    public static function summary(array $user, array $query): never
    {
        [$scope, $userId] = self::resolveScope($user, $query);
        $period = self::resolvePeriod($query);
        $settings = self::settingsRow();

        $facturacion = self::facturacion($period, $userId);
        $movimientos = self::movimientos($period, $userId);
        $congruencia = self::congruenciaData($period, $userId, $movimientos, $settings);

        Response::json([
            'scope' => $scope,
            'user_id' => $userId,
            'period' => self::periodJson($period),
            'settings' => self::settingsPayload($settings, true),
            'facturacion' => $facturacion,
            'movimientos' => $movimientos,
            'congruencia' => $congruencia['resumen'],
            'por_forma_pago' => self::porFormaPago($period, $userId),
            'por_mes' => self::porMes($period, $userId),
            'alertas' => $congruencia['alertas'],
        ]);
    }

    // ------------------------------------------------------------------
    // Congruencia (§4.2)
    // ------------------------------------------------------------------

    public static function congruence(array $user, array $query): never
    {
        [$scope, $userId] = self::resolveScope($user, $query);
        $period = self::resolvePeriod($query);
        $settings = self::settingsRow();

        $movimientos = self::movimientos($period, $userId);
        $congruencia = self::congruenciaData($period, $userId, $movimientos, $settings);

        Response::json([
            'scope' => $scope,
            'user_id' => $userId,
            'period' => self::periodJson($period),
            'estado' => $congruencia['resumen']['estado'],
            'resumen' => $congruencia['resumen'],
            'depositos' => self::congruenceTransfers($period, $userId, 'IN'),
            'retiros' => self::congruenceTransfers($period, $userId, 'OUT'),
            'cfdi_sin_deposito' => self::cfdiSinDepositoList($period, $userId),
            'cfdi_cancelados_con_deposito' => self::cfdiCanceladosConDepositoList($period, $userId),
            'cuadre_ledger' => $congruencia['resumen']['cuadre_ledger'],
            'alertas' => $congruencia['alertas'],
        ]);
    }

    // ------------------------------------------------------------------
    // CFDI (§4.3)
    // ------------------------------------------------------------------

    public static function invoices(array $user, array $query): never
    {
        [$scope, $userId] = self::resolveScope($user, $query);
        $period = self::resolvePeriod($query);

        $estatus = strtoupper(self::queryValue($query, 'estatus'));
        if ($estatus !== '' && !in_array($estatus, ['VIGENTE', 'CANCELADA'], true)) {
            throw new AppException(400, 'Estatus inválido');
        }

        $pdo = Database::pdo();
        $from = self::fromSql($period);
        $to = self::toSql($period);

        [$cond, $extra] = self::userCondition($userId, 'user_id');
        $totalsStmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN estatus = 'VIGENTE' THEN 1 ELSE 0 END), 0) AS vigentes,
                COALESCE(SUM(CASE WHEN estatus = 'CANCELADA' THEN 1 ELSE 0 END), 0) AS cancelados,
                COALESCE(SUM(CASE WHEN estatus = 'VIGENTE' THEN subtotal ELSE 0 END), 0) AS subtotal,
                COALESCE(SUM(CASE WHEN estatus = 'VIGENTE' THEN iva ELSE 0 END), 0) AS iva,
                COALESCE(SUM(CASE WHEN estatus = 'VIGENTE' THEN total ELSE 0 END), 0) AS total,
                COALESCE(SUM(CASE WHEN estatus = 'CANCELADA' THEN total ELSE 0 END), 0) AS cancelado_total
             FROM cfdi_invoices
             WHERE issued_at BETWEEN ? AND ?{$cond}"
        );
        $totalsStmt->execute(array_merge([$from, $to], $extra));
        $totalsRow = $totalsStmt->fetch() ?: [];

        $sql = "SELECT c.id, c.uuid_fiscal, c.tipo, c.estatus, c.issued_at, c.serie, c.folio,
                       c.receptor_rfc, c.receptor_nombre, c.uso_cfdi, c.metodo_pago, c.forma_pago,
                       c.moneda, c.subtotal, c.iva, c.total, c.stp_transfer_id, c.cancelled_at,
                       c.cancel_reason, c.created_at, t.reference
                FROM cfdi_invoices c
                LEFT JOIN stp_transfers t ON t.id = c.stp_transfer_id
                WHERE c.issued_at BETWEEN ? AND ?";
        $params = [$from, $to];
        if ($userId !== null) {
            $sql .= ' AND c.user_id = ?';
            $params[] = $userId;
        }
        if ($estatus !== '') {
            $sql .= ' AND c.estatus = ?';
            $params[] = $estatus;
        }
        $sql .= ' ORDER BY c.issued_at DESC, c.created_at DESC LIMIT 500';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $invoices = [];
        foreach ($stmt->fetchAll() as $row) {
            $invoices[] = [
                'id' => $row['id'],
                'uuid_fiscal' => $row['uuid_fiscal'],
                'tipo' => $row['tipo'],
                'estatus' => $row['estatus'],
                'fecha' => $row['issued_at'],
                'serie' => $row['serie'],
                'folio' => $row['folio'],
                'receptor_rfc' => $row['receptor_rfc'],
                'receptor_nombre' => $row['receptor_nombre'],
                'uso_cfdi' => $row['uso_cfdi'],
                'metodo_pago' => $row['metodo_pago'],
                'forma_pago' => $row['forma_pago'],
                'moneda' => $row['moneda'],
                'subtotal' => self::money($row['subtotal']),
                'iva' => self::money($row['iva']),
                'total' => self::money($row['total']),
                'stp_transfer_id' => $row['stp_transfer_id'],
                'referencia' => $row['reference'] !== null ? (string) $row['reference'] : null,
                'cancelled_at' => $row['cancelled_at'],
                'cancel_reason' => $row['cancel_reason'],
                'created_at' => $row['created_at'],
            ];
        }

        Response::json([
            'scope' => $scope,
            'user_id' => $userId,
            'period' => self::periodJson($period),
            'totals' => [
                'vigentes' => (int) ($totalsRow['vigentes'] ?? 0),
                'cancelados' => (int) ($totalsRow['cancelados'] ?? 0),
                'subtotal' => self::money($totalsRow['subtotal'] ?? 0),
                'iva' => self::money($totalsRow['iva'] ?? 0),
                'total' => self::money($totalsRow['total'] ?? 0),
                'cancelado_total' => self::money($totalsRow['cancelado_total'] ?? 0),
            ],
            'invoices' => $invoices,
        ]);
    }

    // ------------------------------------------------------------------
    // Emisión de CFDI (§3.3) — solo ADMIN
    // ------------------------------------------------------------------

    public static function createInvoice(array $user, array $body): never
    {
        Auth::requireRole($user, ['ADMIN']);

        $uuid = strtolower(self::scalarString($body['uuid'] ?? ''));
        if (!preg_match(self::UUID_REGEX, $uuid)) {
            throw new AppException(400, 'UUID inválido');
        }

        $receptorRfc = strtoupper(self::scalarString($body['receptorRfc'] ?? ''));
        if (!self::isValidRfc($receptorRfc)) {
            throw new AppException(400, 'RFC inválido');
        }

        $receptorNombre = self::scalarString($body['receptorNombre'] ?? '');
        $nombreLen = mb_strlen($receptorNombre);
        if ($nombreLen < 2 || $nombreLen > 255) {
            throw new AppException(400, 'Nombre del receptor inválido (2 a 255 caracteres)');
        }

        $subtotalRaw = $body['subtotal'] ?? null;
        if (!is_numeric($subtotalRaw)) {
            throw new AppException(400, 'Subtotal inválido');
        }
        $subtotal = self::money((float) $subtotalRaw);
        if ($subtotal <= 0) {
            throw new AppException(400, 'Subtotal inválido');
        }

        $settings = self::settingsRow();

        $ivaRateRaw = $body['ivaRate'] ?? null;
        if ($ivaRateRaw !== null && $ivaRateRaw !== '') {
            if (!is_numeric($ivaRateRaw)) {
                throw new AppException(400, 'Tasa de IVA inválida');
            }
            $ivaRate = (float) $ivaRateRaw;
        } else {
            $ivaRate = (float) $settings['iva_rate'];
        }
        if ($ivaRate < 0 || $ivaRate > 1) {
            throw new AppException(400, 'Tasa de IVA inválida');
        }

        $ivaRaw = $body['iva'] ?? null;
        if ($ivaRaw !== null && $ivaRaw !== '') {
            if (!is_numeric($ivaRaw)) {
                throw new AppException(400, 'IVA inválido');
            }
            $iva = self::money((float) $ivaRaw);
            if ($iva < 0) {
                throw new AppException(400, 'IVA inválido');
            }
        } else {
            $iva = self::money($subtotal * $ivaRate);
        }

        $total = self::money($subtotal + $iva);
        $totalRaw = $body['total'] ?? null;
        if ($totalRaw !== null && $totalRaw !== '') {
            if (!is_numeric($totalRaw)) {
                throw new AppException(400, 'Total inválido');
            }
            $totalProvided = round((float) $totalRaw, 2);
            if (abs($totalProvided - $total) > 0.01) {
                throw new AppException(400, 'El total no coincide con subtotal + IVA');
            }
            $total = self::money($totalProvided);
        }

        $tipo = strtoupper(self::scalarString($body['tipo'] ?? '')) ?: 'I';
        if (!in_array($tipo, ['I', 'E', 'P', 'T'], true)) {
            throw new AppException(400, 'Tipo de CFDI inválido');
        }

        $metodoPago = strtoupper(self::scalarString($body['metodoPago'] ?? '')) ?: 'PUE';
        if (!in_array($metodoPago, ['PUE', 'PPD'], true)) {
            throw new AppException(400, 'Método de pago inválido');
        }

        $formaPago = self::scalarString($body['formaPago'] ?? '') ?: '03';
        if (!preg_match('/^\d{2}$/', $formaPago)) {
            throw new AppException(400, 'Forma de pago inválida');
        }

        $usoCfdi = strtoupper(self::scalarString($body['usoCfdi'] ?? '')) ?: 'G03';
        if (!preg_match('/^[A-Z][0-9A-Z]{2}$/', $usoCfdi)) {
            throw new AppException(400, 'Uso de CFDI inválido');
        }

        $receptorRegimen = self::scalarString($body['receptorRegimen'] ?? '') ?: '612';
        if (!preg_match('/^\d{3}$/', $receptorRegimen)) {
            throw new AppException(400, 'Régimen fiscal del receptor inválido');
        }

        $moneda = strtoupper(self::scalarString($body['moneda'] ?? '')) ?: 'MXN';
        if (!preg_match('/^[A-Z]{3}$/', $moneda)) {
            throw new AppException(400, 'Moneda inválida');
        }

        $serie = self::scalarString($body['serie'] ?? '');
        if ($serie === '') {
            $serie = (string) $settings['serie'];
        }
        if (mb_strlen($serie) > 10) {
            throw new AppException(400, 'Serie inválida (máximo 10 caracteres)');
        }

        $folio = self::scalarString($body['folio'] ?? '');
        if (mb_strlen($folio) > 40) {
            throw new AppException(400, 'Folio inválido (máximo 40 caracteres)');
        }

        $uuidRelacionado = strtolower(self::scalarString($body['uuidRelacionado'] ?? ''));
        if ($tipo === 'E' && $uuidRelacionado === '') {
            throw new AppException(400, 'uuidRelacionado es obligatorio para CFDI tipo E');
        }
        if ($uuidRelacionado !== '' && !preg_match(self::UUID_REGEX, $uuidRelacionado)) {
            throw new AppException(400, 'UUID relacionado inválido');
        }

        $tz = self::tz();
        $issuedRaw = self::scalarString($body['issuedAt'] ?? '');
        if ($issuedRaw !== '') {
            $issuedAt = self::parseDateTime($issuedRaw);
            if ($issuedAt === null) {
                throw new AppException(400, 'Fecha de emisión inválida');
            }
        } else {
            $issuedAt = new DateTimeImmutable('now', $tz);
        }
        if ($issuedAt->getTimestamp() > time() + 300) {
            throw new AppException(400, 'La fecha de emisión no puede ser futura');
        }

        $pdo = Database::pdo();

        $transferId = self::scalarString($body['stpTransferId'] ?? '');

        $receptorId = self::scalarString($body['receptorUserId'] ?? '');
        $receptorEmail = strtolower(self::scalarString($body['receptorEmail'] ?? ''));
        if ($receptorId !== '') {
            $receptorStmt = $pdo->prepare('SELECT id, email, name FROM users WHERE id = ?');
            $receptorStmt->execute([$receptorId]);
            $receptor = $receptorStmt->fetch();
            if ($receptor === false) {
                throw new AppException(404, 'Usuario no encontrado');
            }
        } elseif ($receptorEmail !== '') {
            $receptorStmt = $pdo->prepare('SELECT id, email, name FROM users WHERE email = ?');
            $receptorStmt->execute([$receptorEmail]);
            $receptor = $receptorStmt->fetch();
            if ($receptor === false) {
                throw new AppException(404, 'Usuario no encontrado');
            }
        } elseif ($transferId !== '') {
            // Sin receptor explícito pero con transferencia relacionada: el receptor
            // del CFDI se infiere del titular de la transferencia (depósito/retiro).
            // Así una integración puede facturar un depósito sin enviar receptorUserId.
            $ownerStmt = $pdo->prepare(
                'SELECT u.id, u.email, u.name FROM stp_transfers t
                 JOIN users u ON u.id = t.user_id
                 WHERE t.id = ?'
            );
            $ownerStmt->execute([$transferId]);
            $receptor = $ownerStmt->fetch();
            if ($receptor === false) {
                throw new AppException(404, 'Transferencia no encontrada');
            }
        } else {
            $receptor = ['id' => $user['id'], 'email' => $user['email'], 'name' => $user['name']];
        }

        $issuedAtSql = $issuedAt->format('Y-m-d H:i:s');
        $ivaRateSql = number_format($ivaRate, 4, '.', '');

        $pdo->beginTransaction();
        try {
            $dup = $pdo->prepare('SELECT id FROM cfdi_invoices WHERE uuid_fiscal = ?');
            $dup->execute([$uuid]);
            if ($dup->fetchColumn() !== false) {
                throw new AppException(409, 'El UUID fiscal ya existe');
            }

            if ($transferId !== '') {
                $tStmt = $pdo->prepare(
                    'SELECT id, user_id, direction, amount_mxn, amount_cacao, reference
                     FROM stp_transfers WHERE id = ? FOR UPDATE'
                );
                $tStmt->execute([$transferId]);
                $transfer = $tStmt->fetch();
                if ($transfer === false) {
                    throw new AppException(404, 'Transferencia no encontrada');
                }
                if ((string) $transfer['user_id'] !== (string) $receptor['id']) {
                    throw new AppException(400, 'La transferencia no pertenece al receptor');
                }
                $requiredDirection = $tipo === 'E' ? 'OUT' : 'IN';
                if ((string) $transfer['direction'] !== $requiredDirection) {
                    throw new AppException(400, 'La transferencia no corresponde al tipo de CFDI');
                }

                $usedStmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(total), 0) FROM cfdi_invoices
                     WHERE stp_transfer_id = ? AND estatus = 'VIGENTE'"
                );
                $usedStmt->execute([$transferId]);
                $used = (float) $usedStmt->fetchColumn();
                $available = round((float) $transfer['amount_mxn'] - $used, 2);
                if ($total > $available + 0.01) {
                    throw new AppException(400, 'El CFDI excede el monto disponible de la transferencia');
                }
            }

            $id = Database::uuid();
            $insert = $pdo->prepare(
                'INSERT INTO cfdi_invoices
                    (id, uuid_fiscal, tipo, user_id, stp_transfer_id, serie, folio, receptor_rfc,
                     receptor_nombre, receptor_regimen, uso_cfdi, metodo_pago, forma_pago, moneda,
                     subtotal, iva, total, iva_rate, estatus, uuid_relacionado, issued_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $id,
                $uuid,
                $tipo,
                $receptor['id'],
                $transferId !== '' ? $transferId : null,
                $serie,
                $folio,
                $receptorRfc,
                $receptorNombre,
                $receptorRegimen,
                $usoCfdi,
                $metodoPago,
                $formaPago,
                $moneda,
                number_format($subtotal, 2, '.', ''),
                number_format($iva, 2, '.', ''),
                number_format($total, 2, '.', ''),
                $ivaRateSql,
                'VIGENTE',
                $uuidRelacionado !== '' ? $uuidRelacionado : null,
                $issuedAtSql,
                $user['id'],
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PDOException && self::isDuplicateKey($e)) {
                throw new AppException(409, 'El UUID fiscal ya existe');
            }
            throw $e;
        }

        $stmt = $pdo->prepare('SELECT * FROM cfdi_invoices WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new AppException(500, 'No se pudo recuperar el CFDI creado');
        }

        Response::json(['invoice' => self::invoicePayload($row)], 201);
    }

    // ------------------------------------------------------------------
    // Cancelación de CFDI (§3.4) — solo ADMIN
    // ------------------------------------------------------------------

    public static function cancelInvoice(array $user, array $body): never
    {
        Auth::requireRole($user, ['ADMIN']);

        $invoiceId = self::scalarString($body['invoiceId'] ?? '');
        $uuid = strtolower(self::scalarString($body['uuid'] ?? ''));
        if ($invoiceId === '' && $uuid === '') {
            throw new AppException(400, 'Se requiere invoiceId o uuid');
        }

        $reason = self::scalarString($body['reason'] ?? '');
        if ($reason === '') {
            $reason = 'Cancelado desde el sitio';
        }
        if (mb_strlen($reason) > 255) {
            $reason = mb_substr($reason, 0, 255);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $sql = $invoiceId !== ''
                ? 'SELECT * FROM cfdi_invoices WHERE id = ? FOR UPDATE'
                : 'SELECT * FROM cfdi_invoices WHERE uuid_fiscal = ? FOR UPDATE';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$invoiceId !== '' ? $invoiceId : $uuid]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new AppException(404, 'CFDI no encontrado');
            }
            if ((string) $row['estatus'] === 'CANCELADA') {
                throw new AppException(409, 'El CFDI ya está cancelado');
            }

            $cancelledAt = (new DateTimeImmutable('now', self::tz()))->format('Y-m-d H:i:s');
            $update = $pdo->prepare(
                "UPDATE cfdi_invoices
                 SET estatus = 'CANCELADA', cancel_reason = ?, cancelled_at = ?, updated_at = ?
                 WHERE id = ?"
            );
            $update->execute([$reason, $cancelledAt, $cancelledAt, $row['id']]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $stmt = $pdo->prepare('SELECT * FROM cfdi_invoices WHERE id = ?');
        $stmt->execute([$row['id']]);
        $updated = $stmt->fetch();
        if ($updated === false) {
            throw new AppException(500, 'No se pudo recuperar el CFDI cancelado');
        }

        Response::json(['invoice' => self::invoicePayload($updated)]);
    }

    // ------------------------------------------------------------------
    // Exportación CSV (§4.4)
    // ------------------------------------------------------------------

    public static function export(array $user, array $query): never
    {
        [$scope, $userId] = self::resolveScope($user, $query);
        $period = self::resolvePeriod($query);

        $type = strtolower(self::queryValue($query, 'type'));
        if ($type === '') {
            $type = 'cfdi';
        }
        if (!in_array($type, ['cfdi', 'movimientos', 'congruencia'], true)) {
            throw new AppException(400, 'Tipo de exportación inválido');
        }

        $format = strtolower(self::queryValue($query, 'format'));
        if ($format === '') {
            $format = 'csv';
        }
        if ($format !== 'csv') {
            throw new AppException(400, 'Formato no soportado');
        }

        $settings = self::settingsRow();

        if ($type === 'cfdi') {
            [$headers, $rows] = self::exportCfdi($period, $userId, $settings);
        } elseif ($type === 'movimientos') {
            [$headers, $rows] = self::exportMovimientos($period, $userId);
        } else {
            [$headers, $rows] = self::exportCongruencia($period, $userId);
        }

        $filename = $type . '_' . $period['from'] . '_' . $period['to'] . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Access-Control-Expose-Headers: Content-Disposition');

        $csv = "\xEF\xBB\xBF" . self::csvLine($headers);
        foreach ($rows as $row) {
            $csv .= self::csvLine($row);
        }
        echo $csv;
        exit;
    }

    // ------------------------------------------------------------------
    // Cierres de periodo (§3.6, §3.7)
    // ------------------------------------------------------------------

    public static function periods(array $user): never
    {
        $pdo = Database::pdo();
        $stmt = $pdo->query(
            'SELECT p.id, p.period_key, p.revision, p.from_date, p.to_date, p.totals,
                    p.digest, p.prev_digest, p.closed_by, p.closed_at, u.name AS closed_by_name
             FROM fiscal_periods p
             LEFT JOIN users u ON u.id = p.closed_by
             ORDER BY p.closed_at DESC, p.revision DESC'
        );
        $rows = $stmt !== false ? $stmt->fetchAll() : [];

        $periods = [];
        foreach ($rows as $row) {
            $periods[] = [
                'id' => $row['id'],
                'period_key' => $row['period_key'],
                'revision' => (int) $row['revision'],
                'from_date' => $row['from_date'],
                'to_date' => $row['to_date'],
                'digest' => $row['digest'],
                'prev_digest' => $row['prev_digest'],
                'closed_at' => $row['closed_at'],
                'closed_by' => $row['closed_by'] !== null
                    ? ['id' => $row['closed_by'], 'name' => (string) $row['closed_by_name']]
                    : null,
                'totals' => self::decodeJson($row['totals']),
            ];
        }

        Response::json(['periods' => $periods]);
    }

    public static function closePeriod(array $user, array $body): never
    {
        Auth::requireRole($user, ['ADMIN']);

        $periodKey = self::scalarString($body['period'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodKey)) {
            throw new AppException(400, 'Periodo inválido (YYYY-MM)');
        }

        $now = new DateTimeImmutable('now', self::tz());
        if ($periodKey > $now->format('Y-m')) {
            throw new AppException(400, 'No se puede cerrar un periodo futuro');
        }

        $period = self::monthPeriod($periodKey);
        $settings = self::settingsRow();

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // Bloquea el último cierre global para que la cadena no se parta.
            $lastStmt = $pdo->query(
                'SELECT id, digest FROM fiscal_periods ORDER BY closed_at DESC, id DESC LIMIT 1 FOR UPDATE'
            );
            $last = $lastStmt !== false ? $lastStmt->fetch() : false;
            $prevDigest = ($last !== false && $last !== null) ? (string) $last['digest'] : null;

            $revStmt = $pdo->prepare('SELECT COALESCE(MAX(revision), 0) FROM fiscal_periods WHERE period_key = ?');
            $revStmt->execute([$periodKey]);
            $revision = (int) $revStmt->fetchColumn() + 1;

            $facturacion = self::facturacion($period, null);
            $movimientos = self::movimientos($period, null);
            $congruencia = self::congruenciaData($period, null, $movimientos, $settings);

            $totals = [
                'period' => self::periodJson($period),
                'facturacion' => $facturacion,
                'movimientos' => $movimientos,
                'congruencia' => $congruencia['resumen'],
                'por_forma_pago' => self::porFormaPago($period, null),
                'por_mes' => self::porMes($period, null),
                'alertas' => $congruencia['alertas'],
            ];

            $canonical = (string) json_encode(self::canonicalize($totals), JSON_UNESCAPED_UNICODE);
            $digest = hash('sha256', ($prevDigest ?? '') . '|' . $canonical);

            $id = Database::uuid();
            $insert = $pdo->prepare(
                'INSERT INTO fiscal_periods
                    (id, period_key, revision, from_date, to_date, totals, digest, prev_digest, closed_by, closed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $id,
                $periodKey,
                $revision,
                $period['from'],
                $period['to'],
                (string) json_encode($totals, JSON_UNESCAPED_UNICODE),
                $digest,
                $prevDigest,
                $user['id'],
                $now->format('Y-m-d H:i:s'),
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $stmt = $pdo->prepare('SELECT * FROM fiscal_periods WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new AppException(500, 'No se pudo recuperar el cierre de periodo');
        }

        Response::json([
            'period' => [
                'id' => $row['id'],
                'period_key' => $row['period_key'],
                'revision' => (int) $row['revision'],
                'from_date' => $row['from_date'],
                'to_date' => $row['to_date'],
                'digest' => $row['digest'],
                'prev_digest' => $row['prev_digest'],
                'closed_at' => $row['closed_at'],
                'closed_by' => $row['closed_by'],
                'totals' => self::decodeJson($row['totals']),
            ],
        ], 201);
    }

    // ------------------------------------------------------------------
    // Helpers: configuración
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function settingsRow(): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->query(
            'SELECT id, rfc_emisor, razon_social, regimen_fiscal, codigo_postal, serie, iva_rate, moneda
             FROM fiscal_settings WHERE id = 1'
        );
        $row = $stmt !== false ? $stmt->fetch() : false;
        if ($row === false) {
            self::ensureSettings();
            $stmt = $pdo->query(
                'SELECT id, rfc_emisor, razon_social, regimen_fiscal, codigo_postal, serie, iva_rate, moneda
                 FROM fiscal_settings WHERE id = 1'
            );
            $row = $stmt !== false ? $stmt->fetch() : false;
        }
        if ($row === false) {
            throw new AppException(500, 'Configuración fiscal no disponible');
        }
        return $row;
    }

    /** @param array<string,mixed> $row */
    private static function settingsPayload(array $row, bool $withConfigured = false): array
    {
        $payload = [
            'rfc_emisor' => (string) $row['rfc_emisor'],
            'razon_social' => (string) $row['razon_social'],
            'regimen_fiscal' => (string) $row['regimen_fiscal'],
            'codigo_postal' => (string) $row['codigo_postal'],
            'serie' => (string) $row['serie'],
            'iva_rate' => (float) $row['iva_rate'],
            'moneda' => (string) $row['moneda'],
        ];
        if ($withConfigured) {
            $payload['configured'] = self::isConfigured($row);
        }
        return $payload;
    }

    /** @param array<string,mixed> $row */
    private static function isConfigured(array $row): bool
    {
        return trim((string) $row['rfc_emisor']) !== '';
    }

    // ------------------------------------------------------------------
    // Helpers: alcance y periodo
    // ------------------------------------------------------------------

    /**
     * @return array{0: string, 1: ?string} [scope, user_id] donde user_id null = global
     */
    private static function resolveScope(array $user, array $query): array
    {
        if ((string) $user['role'] !== 'ADMIN') {
            return ['OWN', (string) $user['id']];
        }

        $filter = self::queryValue($query, 'user_id');
        if ($filter === '') {
            return ['GLOBAL', null];
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->execute([$filter]);
        if ($stmt->fetchColumn() === false) {
            throw new AppException(404, 'Usuario no encontrado');
        }

        return ['GLOBAL', $filter];
    }

    /**
     * @return array{from: string, to: string, label: string, days: int, from_dt: DateTimeImmutable, to_dt: DateTimeImmutable}
     */
    private static function resolvePeriod(array $query): array
    {
        $now = new DateTimeImmutable('now', self::tz());

        $fromRaw = self::queryValue($query, 'from');
        $toRaw = self::queryValue($query, 'to');
        $fromStr = $fromRaw !== '' ? $fromRaw : $now->format('Y-m-01');
        $toStr = $toRaw !== '' ? $toRaw : $now->format('Y-m-t');

        $from = self::parseDate($fromStr);
        $to = self::parseDate($toStr);
        if ($from === null || $to === null) {
            throw new AppException(400, 'Fecha inválida (YYYY-MM-DD)');
        }
        if ($from > $to) {
            throw new AppException(400, 'Periodo inválido');
        }

        $period = self::makePeriod($from, $to);
        if ($period['days'] > 366) {
            throw new AppException(400, 'El periodo no puede exceder 366 días');
        }

        return $period;
    }

    /**
     * @return array{from: string, to: string, label: string, days: int, from_dt: DateTimeImmutable, to_dt: DateTimeImmutable}
     */
    private static function monthPeriod(string $periodKey): array
    {
        $from = new DateTimeImmutable($periodKey . '-01 00:00:00', self::tz());
        $to = $from->setDate((int) $from->format('Y'), (int) $from->format('n'), (int) $from->format('t'));

        return self::makePeriod($from, $to);
    }

    /**
     * @return array{from: string, to: string, label: string, days: int, from_dt: DateTimeImmutable, to_dt: DateTimeImmutable}
     */
    private static function makePeriod(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'label' => self::periodLabel($from, $to),
            'days' => (int) $from->diff($to)->days + 1,
            'from_dt' => $from,
            'to_dt' => $to,
        ];
    }

    /** @return array{from: string, to: string, label: string, days: int} */
    private static function periodJson(array $period): array
    {
        return [
            'from' => $period['from'],
            'to' => $period['to'],
            'label' => $period['label'],
            'days' => $period['days'],
        ];
    }

    private static function periodLabel(DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        $name = static fn (DateTimeImmutable $d): string => self::MESES[(int) $d->format('n')];

        if ($from->format('Y-m') === $to->format('Y-m')) {
            return $name($from) . ' ' . $from->format('Y');
        }
        if ($from->format('Y') === $to->format('Y')) {
            return $name($from) . ' – ' . $name($to) . ' ' . $from->format('Y');
        }

        return $name($from) . ' ' . $from->format('Y') . ' – ' . $name($to) . ' ' . $to->format('Y');
    }

    private static function parseDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, self::tz());

        return ($date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value) ? $date : null;
    }

    private static function parseDateTime(string $value): ?DateTimeImmutable
    {
        foreach (['Y-m-d H:i:s', 'Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value, self::tz());
            if ($date instanceof DateTimeImmutable && $date->format($format) === $value) {
                return $date;
            }
        }

        return null;
    }

    private static function fromSql(array $period): string
    {
        return $period['from'] . ' 00:00:00';
    }

    private static function toSql(array $period): string
    {
        return $period['to'] . ' 23:59:59';
    }

    /**
     * Condición SQL y parámetros extra para filtrar por usuario (scope OWN o
     * filtro ADMIN ?user_id=).
     *
     * @return array{0: string, 1: array<int,string>}
     */
    private static function userCondition(?string $userId, string $column): array
    {
        return $userId === null ? ['', []] : [' AND ' . $column . ' = ?', [$userId]];
    }

    // ------------------------------------------------------------------
    // Helpers: agregados de facturación y movimientos
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function facturacion(array $period, ?string $userId): array
    {
        [$cond, $extra] = self::userCondition($userId, 'user_id');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN estatus = 'VIGENTE' THEN 1 ELSE 0 END), 0) AS cfdi_vigentes,
                COALESCE(SUM(CASE WHEN estatus = 'CANCELADA' THEN 1 ELSE 0 END), 0) AS cfdi_cancelados,
                COALESCE(SUM(CASE WHEN estatus = 'VIGENTE' AND tipo IN ('P','T') THEN 1 ELSE 0 END), 0) AS otros_count,
                COALESCE(SUM(CASE WHEN estatus = 'VIGENTE' AND tipo = 'I' THEN subtotal ELSE 0 END), 0) AS ingresos_subtotal,
                COALESCE(SUM(CASE WHEN estatus = 'VIGENTE' AND tipo = 'I' THEN iva ELSE 0 END), 0) AS ingresos_iva,
                COALESCE(SUM(CASE WHEN estatus = 'VIGENTE' AND tipo = 'I' THEN total ELSE 0 END), 0) AS ingresos_total,
                COALESCE(SUM(CASE WHEN estatus = 'VIGENTE' AND tipo = 'E' THEN total ELSE 0 END), 0) AS notas_credito_total,
                COALESCE(SUM(CASE WHEN estatus = 'VIGENTE' AND tipo = 'E' THEN iva ELSE 0 END), 0) AS notas_credito_iva
             FROM cfdi_invoices
             WHERE issued_at BETWEEN ? AND ?{$cond}"
        );
        $stmt->execute(array_merge([self::fromSql($period), self::toSql($period)], $extra));
        $row = $stmt->fetch() ?: [];

        $ingresosSubtotal = self::money($row['ingresos_subtotal'] ?? 0);
        $ingresosIva = self::money($row['ingresos_iva'] ?? 0);
        $ingresosTotal = self::money($row['ingresos_total'] ?? 0);
        $notasCredito = self::money($row['notas_credito_total'] ?? 0);
        $notasCreditoIva = self::money($row['notas_credito_iva'] ?? 0);

        return [
            'cfdi_vigentes' => (int) ($row['cfdi_vigentes'] ?? 0),
            'cfdi_cancelados' => (int) ($row['cfdi_cancelados'] ?? 0),
            'otros_count' => (int) ($row['otros_count'] ?? 0),
            'ingresos_subtotal' => $ingresosSubtotal,
            'ingresos_iva' => $ingresosIva,
            'ingresos_total' => $ingresosTotal,
            'notas_credito_total' => $notasCredito,
            'facturado_neto' => self::money($ingresosTotal - $notasCredito),
            'iva_neto' => self::money($ingresosIva - $notasCreditoIva),
        ];
    }

    /** @return array<string,mixed> */
    private static function movimientos(array $period, ?string $userId): array
    {
        [$cond, $extra] = self::userCondition($userId, 'user_id');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN direction = 'IN' THEN amount_mxn ELSE 0 END), 0) AS depositos_mxn,
                COALESCE(SUM(CASE WHEN direction = 'IN' THEN 1 ELSE 0 END), 0) AS depositos_count,
                COALESCE(SUM(CASE WHEN direction = 'OUT' THEN amount_mxn ELSE 0 END), 0) AS retiros_mxn,
                COALESCE(SUM(CASE WHEN direction = 'OUT' THEN 1 ELSE 0 END), 0) AS retiros_count
             FROM stp_transfers
             WHERE created_at BETWEEN ? AND ?{$cond}"
        );
        $stmt->execute(array_merge([self::fromSql($period), self::toSql($period)], $extra));
        $row = $stmt->fetch() ?: [];

        $depositos = self::money($row['depositos_mxn'] ?? 0);
        $retiros = self::money($row['retiros_mxn'] ?? 0);

        return [
            'depositos_mxn' => $depositos,
            'depositos_count' => (int) ($row['depositos_count'] ?? 0),
            'retiros_mxn' => $retiros,
            'retiros_count' => (int) ($row['retiros_count'] ?? 0),
            'neto_mxn' => self::money($depositos - $retiros),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function porFormaPago(array $period, ?string $userId): array
    {
        [$cond, $extra] = self::userCondition($userId, 'user_id');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT forma_pago,
                    COUNT(*) AS formas_count,
                    COALESCE(SUM(total), 0) AS total_sum
             FROM cfdi_invoices
             WHERE estatus = 'VIGENTE' AND issued_at BETWEEN ? AND ?{$cond}
             GROUP BY forma_pago
             ORDER BY total_sum DESC, forma_pago ASC"
        );
        $stmt->execute(array_merge([self::fromSql($period), self::toSql($period)], $extra));

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $forma = (string) $row['forma_pago'];
            $out[] = [
                'forma_pago' => $forma,
                'label' => self::FORMA_PAGO_LABELS[$forma] ?? 'Otros',
                'count' => (int) $row['formas_count'],
                'total' => self::money($row['total_sum']),
            ];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function porMes(array $period, ?string $userId): array
    {
        [$cond, $extra] = self::userCondition($userId, 'user_id');
        $pdo = Database::pdo();
        $from = self::fromSql($period);
        $to = self::toSql($period);

        $cfdiStmt = $pdo->prepare(
            "SELECT DATE_FORMAT(issued_at, '%Y-%m') AS periodo,
                    COALESCE(SUM(CASE WHEN tipo = 'I' THEN total ELSE 0 END), 0) AS facturado,
                    COALESCE(SUM(CASE WHEN tipo = 'I' THEN iva ELSE 0 END), 0) AS iva
             FROM cfdi_invoices
             WHERE estatus = 'VIGENTE' AND issued_at BETWEEN ? AND ?{$cond}
             GROUP BY periodo"
        );
        $cfdiStmt->execute(array_merge([$from, $to], $extra));
        $cfdiByMonth = [];
        foreach ($cfdiStmt->fetchAll() as $row) {
            $cfdiByMonth[(string) $row['periodo']] = $row;
        }

        $stpStmt = $pdo->prepare(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS periodo,
                    COALESCE(SUM(CASE WHEN direction = 'IN' THEN amount_mxn ELSE 0 END), 0) AS depositos,
                    COALESCE(SUM(CASE WHEN direction = 'OUT' THEN amount_mxn ELSE 0 END), 0) AS retiros
             FROM stp_transfers
             WHERE created_at BETWEEN ? AND ?{$cond}
             GROUP BY periodo"
        );
        $stpStmt->execute(array_merge([$from, $to], $extra));
        $stpByMonth = [];
        foreach ($stpStmt->fetchAll() as $row) {
            $stpByMonth[(string) $row['periodo']] = $row;
        }

        $cursor = $period['from_dt']->modify('first day of this month');
        $end = $period['to_dt']->modify('first day of this month');
        $months = [];
        while ($cursor <= $end && count($months) < 24) {
            $key = $cursor->format('Y-m');
            $cfdi = $cfdiByMonth[$key] ?? null;
            $stp = $stpByMonth[$key] ?? null;
            $months[] = [
                'period' => $key,
                'facturado' => self::money($cfdi['facturado'] ?? 0),
                'iva' => self::money($cfdi['iva'] ?? 0),
                'depositos' => self::money($stp['depositos'] ?? 0),
                'retiros' => self::money($stp['retiros'] ?? 0),
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }

    // ------------------------------------------------------------------
    // Helpers: congruencia (§3.8, §4.2)
    // ------------------------------------------------------------------

    /**
     * @return array{resumen: array<string,mixed>, alertas: array<int,array<string,mixed>>}
     */
    private static function congruenciaData(array $period, ?string $userId, array $movimientos, array $settings): array
    {
        $facturadoEnDepositos = self::facturadoEnDepositos($period, $userId);
        $cfdiSinDeposito = self::cfdiSinDepositoTotal($period, $userId);
        $canceladoConDeposito = self::cfdiCanceladoConDepositoTotal($period, $userId);

        $depositosLedger = self::ledgerSum($period, $userId, 'DEPOSIT');
        $depositosStp = self::stpSum($period, $userId, 'IN');
        $retirosLedger = self::ledgerSum($period, $userId, 'WITHDRAW');
        $retirosStp = self::stpSum($period, $userId, 'OUT');

        $ledgerOk = abs($depositosLedger - $depositosStp) <= 0.01
            && abs($retirosLedger - $retirosStp) <= 0.01;

        $depositosSinFacturar = self::money($movimientos['depositos_mxn'] - $facturadoEnDepositos);
        $diferencia = self::money($depositosSinFacturar - $cfdiSinDeposito);
        $cobertura = $movimientos['depositos_mxn'] > 0
            ? self::money($facturadoEnDepositos / $movimientos['depositos_mxn'] * 100)
            : 100.0;

        $estado = ($depositosSinFacturar <= 0.01 && $cfdiSinDeposito <= 0.01 && $ledgerOk)
            ? 'CUADRA'
            : 'REVISAR';

        $resumen = [
            'facturado_en_depositos' => $facturadoEnDepositos,
            'depositos_sin_facturar_total' => $depositosSinFacturar,
            'cfdi_sin_deposito_total' => $cfdiSinDeposito,
            'diferencia_total' => $diferencia,
            'cobertura_pct' => $cobertura,
            'estado' => $estado,
            'cuadre_ledger' => [
                'depositos_ledger' => $depositosLedger,
                'depositos_stp' => $depositosStp,
                'retiros_ledger' => $retirosLedger,
                'retiros_stp' => $retirosStp,
                'ok' => $ledgerOk,
            ],
        ];

        return [
            'resumen' => $resumen,
            'alertas' => self::buildAlerts($settings, $resumen, $canceladoConDeposito),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function buildAlerts(array $settings, array $resumen, float $canceladoConDeposito): array
    {
        $alerts = [];

        if (trim((string) $settings['rfc_emisor']) === '') {
            $alerts[] = [
                'tipo' => 'CONFIGURACION_FISCAL',
                'severidad' => 'MEDIA',
                'mensaje' => 'Configura el RFC del emisor para emitir CFDI congruentes.',
                'monto' => 0.0,
            ];
        }

        if ($resumen['depositos_sin_facturar_total'] > 0.01) {
            $alerts[] = [
                'tipo' => 'DEPOSITOS_SIN_FACTURA',
                'severidad' => 'ALTA',
                'mensaje' => 'Hay depósitos del periodo sin CFDI que los respalde.',
                'monto' => $resumen['depositos_sin_facturar_total'],
            ];
        }

        if ($resumen['cfdi_sin_deposito_total'] > 0.01) {
            $alerts[] = [
                'tipo' => 'CFDI_SIN_DEPOSITO',
                'severidad' => 'ALTA',
                'mensaje' => 'Hay CFDI vigentes de ingreso sin depósito que los respalde (facturado no cobrado).',
                'monto' => $resumen['cfdi_sin_deposito_total'],
            ];
        }

        if ($canceladoConDeposito > 0.01) {
            $alerts[] = [
                'tipo' => 'CFDI_CANCELADO_CON_DEPOSITO',
                'severidad' => 'ALTA',
                'mensaje' => 'Hay CFDI cancelados que sí estaban ligados a un depósito.',
                'monto' => $canceladoConDeposito,
            ];
        }

        if (!$resumen['cuadre_ledger']['ok']) {
            $diferenciaLedger = self::money(
                abs($resumen['cuadre_ledger']['depositos_ledger'] - $resumen['cuadre_ledger']['depositos_stp'])
                + abs($resumen['cuadre_ledger']['retiros_ledger'] - $resumen['cuadre_ledger']['retiros_stp'])
            );
            $alerts[] = [
                'tipo' => 'LEDGER_DESCUADRE',
                'severidad' => 'ALTA',
                'mensaje' => 'El libro mayor no cuadra contra las transferencias STP del periodo.',
                'monto' => $diferenciaLedger,
            ];
        }

        return array_slice($alerts, 0, 50);
    }

    private static function facturadoEnDepositos(array $period, ?string $userId): float
    {
        [$cond, $extra] = self::userCondition($userId, 't.user_id');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT t.id, t.amount_mxn,
                    COALESCE(SUM(CASE WHEN c.estatus = 'VIGENTE' THEN c.total ELSE 0 END), 0) AS facturado
             FROM stp_transfers t
             LEFT JOIN cfdi_invoices c ON c.stp_transfer_id = t.id
             WHERE t.direction = 'IN' AND t.created_at BETWEEN ? AND ?{$cond}
             GROUP BY t.id, t.amount_mxn"
        );
        $stmt->execute(array_merge([self::fromSql($period), self::toSql($period)], $extra));

        $sum = 0.0;
        foreach ($stmt->fetchAll() as $row) {
            $sum += min((float) $row['facturado'], (float) $row['amount_mxn']);
        }

        return self::money($sum);
    }

    private static function cfdiSinDepositoTotal(array $period, ?string $userId): float
    {
        [$cond, $extra] = self::userCondition($userId, 'user_id');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(total), 0) FROM cfdi_invoices
             WHERE estatus = 'VIGENTE' AND tipo = 'I' AND stp_transfer_id IS NULL
               AND issued_at BETWEEN ? AND ?{$cond}"
        );
        $stmt->execute(array_merge([self::fromSql($period), self::toSql($period)], $extra));

        return self::money($stmt->fetchColumn());
    }

    private static function cfdiCanceladoConDepositoTotal(array $period, ?string $userId): float
    {
        [$cond, $extra] = self::userCondition($userId, 'user_id');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(total), 0) FROM cfdi_invoices
             WHERE estatus = 'CANCELADA' AND stp_transfer_id IS NOT NULL
               AND issued_at BETWEEN ? AND ?{$cond}"
        );
        $stmt->execute(array_merge([self::fromSql($period), self::toSql($period)], $extra));

        return self::money($stmt->fetchColumn());
    }

    private static function ledgerSum(array $period, ?string $userId, string $entryType): float
    {
        $pdo = Database::pdo();
        $column = $entryType === 'DEPOSIT' ? 'l.credit_account_id' : 'l.debit_account_id';
        $from = self::fromSql($period);
        $to = self::toSql($period);

        if ($userId === null) {
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(l.amount), 0) FROM ledger_entries l
                 WHERE l.entry_type = ?
                   AND JSON_UNQUOTE(JSON_EXTRACT(l.meta, '$.via')) = 'STP'
                   AND l.created_at BETWEEN ? AND ?"
            );
            $stmt->execute([$entryType, $from, $to]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(l.amount), 0) FROM ledger_entries l
                 JOIN accounts a ON a.id = {$column}
                 WHERE a.user_id = ?
                   AND l.entry_type = ?
                   AND JSON_UNQUOTE(JSON_EXTRACT(l.meta, '$.via')) = 'STP'
                   AND l.created_at BETWEEN ? AND ?"
            );
            $stmt->execute([$userId, $entryType, $from, $to]);
        }

        return self::money($stmt->fetchColumn());
    }

    private static function stpSum(array $period, ?string $userId, string $direction): float
    {
        [$cond, $extra] = self::userCondition($userId, 'user_id');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount_cacao), 0) FROM stp_transfers
             WHERE direction = ? AND created_at BETWEEN ? AND ?{$cond}"
        );
        $stmt->execute(array_merge([$direction, self::fromSql($period), self::toSql($period)], $extra));

        return self::money($stmt->fetchColumn());
    }

    /**
     * Todas las transferencias del periodo (hasta $limit), con su cobertura
     * fiscal (CFDI VIGENTES ligados, sin filtro de periodo).
     *
     * @return array<int,array<string,mixed>>
     */
    private static function transferRows(array $period, ?string $userId, ?string $direction, int $limit = 500): array
    {
        [$cond, $extra] = self::userCondition($userId, 't.user_id');
        $pdo = Database::pdo();
        $sql = "SELECT t.id, t.user_id, t.direction, t.created_at, t.amount_mxn, t.amount_cacao,
                       t.reference, t.account_number, t.bank_name, t.beneficiary,
                       u.email, u.name
                FROM stp_transfers t
                JOIN users u ON u.id = t.user_id
                WHERE t.created_at BETWEEN ? AND ?";
        $params = [self::fromSql($period), self::toSql($period)];
        if ($direction !== null) {
            $sql .= ' AND t.direction = ?';
            $params[] = $direction;
        }
        $sql .= $cond;
        $params = array_merge($params, $extra);
        $sql .= ' ORDER BY t.created_at DESC LIMIT ' . max(1, min($limit, 5000));

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Cobertura fiscal por transferencia: Σ total de CFDI VIGENTES ligados y
     * lista de UUIDs. Clave = stp_transfer_id.
     *
     * @return array<string, array{facturado: float, uuids: array<int,string>}>
     */
    private static function transferCoverage(?string $userId): array
    {
        [$cond, $extra] = self::userCondition($userId, 'user_id');
        $pdo = Database::pdo();
        $pdo->exec('SET SESSION group_concat_max_len = 1000000');
        $stmt = $pdo->prepare(
            "SELECT stp_transfer_id,
                    COALESCE(SUM(total), 0) AS facturado,
                    GROUP_CONCAT(uuid_fiscal ORDER BY created_at SEPARATOR ',') AS uuids
             FROM cfdi_invoices
             WHERE estatus = 'VIGENTE' AND stp_transfer_id IS NOT NULL{$cond}
             GROUP BY stp_transfer_id"
        );
        $stmt->execute($extra);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $raw = $row['uuids'] !== null ? (string) $row['uuids'] : '';
            $map[(string) $row['stp_transfer_id']] = [
                'facturado' => self::money($row['facturado']),
                'uuids' => $raw === '' ? [] : explode(',', $raw),
            ];
        }

        return $map;
    }

    private static function transferEstado(float $monto, float $facturado): string
    {
        if ($facturado > $monto + 0.01) {
            return 'EXCEDE';
        }
        if ($facturado >= $monto - 0.01) {
            return 'FACTURADO';
        }
        if ($facturado > 0.01) {
            return 'PARCIAL';
        }

        return 'SIN_FACTURA';
    }

    /** @return array<int,array<string,mixed>> */
    private static function congruenceTransfers(array $period, ?string $userId, string $direction, int $limit = 500): array
    {
        $coverage = self::transferCoverage($userId);
        $out = [];

        foreach (self::transferRows($period, $userId, $direction, $limit) as $row) {
            $monto = self::money($row['amount_mxn']);
            $cov = $coverage[(string) $row['id']] ?? ['facturado' => 0.0, 'uuids' => []];
            $facturado = $cov['facturado'];
            $estado = self::transferEstado($monto, $facturado);
            $diferencia = self::money($monto - $facturado);

            if ($direction === 'IN') {
                $out[] = [
                    'id' => $row['id'],
                    'fecha' => $row['created_at'],
                    'monto_mxn' => $monto,
                    'amount_cacao' => self::money($row['amount_cacao']),
                    'referencia' => (string) $row['reference'],
                    'receptor' => [
                        'id' => $row['user_id'],
                        'email' => $row['email'],
                        'name' => $row['name'],
                        'rfc' => null,
                    ],
                    'facturado' => $facturado,
                    'cfdi_uuids' => $cov['uuids'],
                    'estado' => $estado,
                    'diferencia' => $diferencia,
                ];
            } else {
                $out[] = [
                    'id' => $row['id'],
                    'fecha' => $row['created_at'],
                    'monto_mxn' => $monto,
                    'clabe' => (string) $row['account_number'],
                    'beneficiario' => (string) $row['beneficiary'],
                    'banco' => (string) $row['bank_name'],
                    'facturado' => $facturado,
                    'cfdi_uuids' => $cov['uuids'],
                    'estado' => $estado,
                    'diferencia' => $diferencia,
                ];
            }
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function cfdiSinDepositoList(array $period, ?string $userId, int $limit = 500): array
    {
        [$cond, $extra] = self::userCondition($userId, 'user_id');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT id, uuid_fiscal, tipo, receptor_rfc, issued_at, total
             FROM cfdi_invoices
             WHERE estatus = 'VIGENTE' AND tipo = 'I' AND stp_transfer_id IS NULL
               AND issued_at BETWEEN ? AND ?{$cond}
             ORDER BY created_at DESC LIMIT " . max(1, min($limit, 5000))
        );
        $stmt->execute(array_merge([self::fromSql($period), self::toSql($period)], $extra));

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id' => $row['id'],
                'uuid_fiscal' => $row['uuid_fiscal'],
                'tipo' => $row['tipo'],
                'receptor_rfc' => $row['receptor_rfc'],
                'issued_at' => $row['issued_at'],
                'total' => self::money($row['total']),
            ];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function cfdiCanceladosConDepositoList(array $period, ?string $userId, int $limit = 500): array
    {
        [$cond, $extra] = self::userCondition($userId, 'user_id');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT id, uuid_fiscal, tipo, stp_transfer_id, issued_at, total
             FROM cfdi_invoices
             WHERE estatus = 'CANCELADA' AND stp_transfer_id IS NOT NULL
               AND issued_at BETWEEN ? AND ?{$cond}
             ORDER BY created_at DESC LIMIT " . max(1, min($limit, 5000))
        );
        $stmt->execute(array_merge([self::fromSql($period), self::toSql($period)], $extra));

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id' => $row['id'],
                'uuid_fiscal' => $row['uuid_fiscal'],
                'tipo' => $row['tipo'],
                'stp_transfer_id' => $row['stp_transfer_id'],
                'total' => self::money($row['total']),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Helpers: exportación CSV
    // ------------------------------------------------------------------

    /** @return array{0: array<int,string>, 1: array<int,array<int,string>>} */
    private static function exportCfdi(array $period, ?string $userId, array $settings): array
    {
        [$cond, $extra] = self::userCondition($userId, 'c.user_id');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT c.uuid_fiscal, c.tipo, c.estatus, c.issued_at, c.serie, c.folio,
                    c.receptor_rfc, c.receptor_nombre, c.receptor_regimen, c.uso_cfdi,
                    c.metodo_pago, c.forma_pago, c.moneda, c.subtotal, c.iva, c.total,
                    c.uuid_relacionado, c.cancel_reason, t.reference
             FROM cfdi_invoices c
             LEFT JOIN stp_transfers t ON t.id = c.stp_transfer_id
             WHERE c.issued_at BETWEEN ? AND ?{$cond}
             ORDER BY c.issued_at DESC, c.created_at DESC"
        );
        $stmt->execute(array_merge([self::fromSql($period), self::toSql($period)], $extra));

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                (string) $row['uuid_fiscal'],
                (string) $row['tipo'],
                (string) $row['estatus'],
                (string) $row['issued_at'],
                (string) $row['serie'],
                (string) $row['folio'],
                (string) $settings['rfc_emisor'],
                (string) $settings['razon_social'],
                (string) $row['receptor_rfc'],
                (string) $row['receptor_nombre'],
                (string) $row['receptor_regimen'],
                (string) $row['uso_cfdi'],
                (string) $row['metodo_pago'],
                (string) $row['forma_pago'],
                (string) $row['moneda'],
                self::decimal($row['subtotal']),
                self::decimal($row['iva']),
                self::decimal($row['total']),
                (string) ($row['uuid_relacionado'] ?? ''),
                (string) ($row['reference'] ?? ''),
                (string) ($row['cancel_reason'] ?? ''),
            ];
        }

        return [self::CFDI_EXPORT_HEADERS, $rows];
    }

    /** @return array{0: array<int,string>, 1: array<int,array<int,string>>} */
    private static function exportMovimientos(array $period, ?string $userId): array
    {
        $coverage = self::transferCoverage($userId);
        $rows = [];

        foreach (self::transferRows($period, $userId, null, 5000) as $row) {
            $monto = self::money($row['amount_mxn']);
            $cov = $coverage[(string) $row['id']] ?? ['facturado' => 0.0, 'uuids' => []];
            $rows[] = [
                (string) $row['created_at'],
                (string) $row['direction'],
                self::decimal($monto),
                self::decimal($row['amount_cacao']),
                (string) $row['account_number'],
                (string) $row['bank_name'],
                (string) $row['beneficiary'],
                (string) $row['reference'],
                (string) $row['id'],
                self::transferEstado($monto, $cov['facturado']),
                implode(' ', $cov['uuids']),
            ];
        }

        return [self::MOVIMIENTOS_EXPORT_HEADERS, $rows];
    }

    /** @return array{0: array<int,string>, 1: array<int,array<int,string>>} */
    private static function exportCongruencia(array $period, ?string $userId): array
    {
        $coverage = self::transferCoverage($userId);
        $rows = [];

        foreach (self::transferRows($period, $userId, null, 5000) as $row) {
            $monto = self::money($row['amount_mxn']);
            $cov = $coverage[(string) $row['id']] ?? ['facturado' => 0.0, 'uuids' => []];
            $diferencia = self::money($monto - $cov['facturado']);
            if ((string) $row['direction'] === 'IN') {
                $concepto = 'DEPOSITO';
                $observacion = (string) $row['reference'] !== '' ? 'Referencia: ' . $row['reference'] : '';
            } else {
                $concepto = 'RETIRO';
                $observacion = 'Beneficiario: ' . $row['beneficiary']
                    . ' · CLABE: ' . $row['account_number']
                    . ' · Banco: ' . $row['bank_name'];
            }
            $rows[] = [
                (string) $row['created_at'],
                $concepto,
                (string) $row['direction'],
                self::decimal($monto),
                self::decimal($cov['facturado']),
                self::decimal($diferencia),
                implode(' ', $cov['uuids']),
                self::transferEstado($monto, $cov['facturado']),
                $observacion,
            ];
        }

        foreach (self::cfdiSinDepositoList($period, $userId, 5000) as $row) {
            $total = self::money($row['total']);
            $rows[] = [
                (string) $row['issued_at'],
                'CFDI_SIN_DEPOSITO',
                (string) $row['tipo'],
                self::decimal($total),
                '0.00',
                self::decimal($total),
                (string) $row['uuid_fiscal'],
                'SIN_DEPOSITO',
                'CFDI vigente sin depósito que lo respalde',
            ];
        }

        [$cond, $extra] = self::userCondition($userId, 'user_id');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT id, uuid_fiscal, tipo, total, issued_at
             FROM cfdi_invoices
             WHERE estatus = 'CANCELADA' AND stp_transfer_id IS NOT NULL
               AND issued_at BETWEEN ? AND ?{$cond}
             ORDER BY created_at DESC LIMIT 5000"
        );
        $stmt->execute(array_merge([self::fromSql($period), self::toSql($period)], $extra));

        foreach ($stmt->fetchAll() as $row) {
            $total = self::money($row['total']);
            $rows[] = [
                (string) $row['issued_at'],
                'CFDI_CANCELADO_CON_DEPOSITO',
                (string) $row['tipo'],
                self::decimal($total),
                '0.00',
                self::decimal($total),
                (string) $row['uuid_fiscal'],
                'CANCELADA',
                'CFDI cancelado con depósito relacionado',
            ];
        }

        return [self::CONGRUENCIA_EXPORT_HEADERS, $rows];
    }

    /** @param array<int,string|int|float|null> $cells */
    private static function csvLine(array $cells): string
    {
        $out = [];
        foreach ($cells as $cell) {
            $out[] = self::csvCell($cell);
        }

        return implode(',', $out) . "\r\n";
    }

    private static function csvCell(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;
        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $text = "'" . $text;
        }

        return '"' . str_replace('"', '""', $text) . '"';
    }

    // ------------------------------------------------------------------
    // Helpers: CFDI / utilidades
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $row */
    private static function invoicePayload(array $row): array
    {
        return [
            'id' => $row['id'],
            'uuid_fiscal' => $row['uuid_fiscal'],
            'tipo' => $row['tipo'],
            'estatus' => $row['estatus'],
            'subtotal' => self::money($row['subtotal']),
            'iva' => self::money($row['iva']),
            'total' => self::money($row['total']),
            'receptor_rfc' => $row['receptor_rfc'],
            'issued_at' => $row['issued_at'],
            'created_at' => $row['created_at'],
        ];
    }

    private static function isValidRfc(string $rfc): bool
    {
        if (in_array($rfc, self::GENERIC_RFCS, true)) {
            return true;
        }

        return (bool) preg_match(self::RFC_REGEX, $rfc);
    }

    /**
     * Ordena recursivamente las claves (ksort) para el JSON canónico del cierre.
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $isList = array_keys($value) === range(0, count($value) - 1);
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::canonicalize($item);
            }
            if (!$isList) {
                ksort($out);
            }
            return $out;
        }

        return $value;
    }

    private static function money(mixed $value): float
    {
        $amount = round((float) $value, 2);

        return $amount == 0.0 ? 0.0 : $amount;
    }

    private static function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private static function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function isDuplicateKey(PDOException $e): bool
    {
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;

        return (string) $e->getCode() === '23000' || $driverCode === 1062;
    }

    private static function scalarString(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function queryValue(array $query, string $key): string
    {
        $value = $query[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function tz(): DateTimeZone
    {
        return new DateTimeZone(self::TZ);
    }
}
