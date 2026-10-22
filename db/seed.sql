SET NAMES utf8mb4;

INSERT IGNORE INTO users (id, email, name, password_hash, role, kyc_status) VALUES
    (UUID(), 'admin@bancocacao.pe',    'Administrador', '$2y$12$43/HB5LULVJWsD91UWFxlumDhfIVK4xsoCYgo0R.BwjuSB6.q7I1e', 'ADMIN',  'VERIFIED'),
    (UUID(), 'vendedor@bancocacao.pe', 'Vendedor',     '$2y$12$ZfXliprWn3bI082FPNif8ua4pNh2ZeWMSvhdAsmhusgOC9ssjNDrq', 'SELLER', 'VERIFIED'),
    (UUID(), 'cliente@bancocacao.pe',  'Cliente',      '$2y$12$sqGI6KDPW.QU.l5ptv.PXO4rL4kOKaW.SoDdGbzplIDm8bfSUcbFm', 'CLIENT', 'VERIFIED');

INSERT IGNORE INTO accounts (id, user_id, kind, currency)
SELECT UUID(), u.id, 'USER', 'CACAO'
FROM users u
WHERE u.email IN ('admin@bancocacao.pe', 'vendedor@bancocacao.pe', 'cliente@bancocacao.pe');

INSERT INTO products (id, name, description, cacao_price, stock, seller_id)
SELECT
    UUID(), v.name, v.description, v.cacao_price, v.stock,
    (SELECT id FROM users WHERE email = 'vendedor@bancocacao.pe')
FROM (
    SELECT 'Bebida de cacao ceremonial' AS name, 'Bebida caliente a base de cacao puro de la selva peruana' AS description, 25 AS cacao_price, 40 AS stock
    UNION ALL SELECT 'Tableta de cacao 70%', 'Chocolate artesanal 70% cacao', 40, 25
    UNION ALL SELECT 'Nibs de cacao tostados', 'Granos de cacao tostado y troceado, 100 g', 15, 60
    UNION ALL SELECT 'Manteca de cacao', 'Manteca de cacao pura para repostería', 35, 20
) AS v
WHERE NOT EXISTS (SELECT 1 FROM products);
