BEGIN;

INSERT INTO users (email, name, password_hash, role, kyc_status)
VALUES
    ('admin@bancocacao.pe',    'Administrador', '$2y$12$43/HB5LULVJWsD91UWFxlumDhfIVK4xsoCYgo0R.BwjuSB6.q7I1e', 'ADMIN',  'VERIFIED'),
    ('vendedor@bancocacao.pe', 'Vendedor',     '$2y$12$ZfXliprWn3bI082FPNif8ua4pNh2ZeWMSvhdAsmhusgOC9ssjNDrq', 'SELLER', 'VERIFIED'),
    ('cliente@bancocacao.pe',  'Cliente',      '$2y$12$sqGI6KDPW.QU.l5ptv.PXO4rL4kOKaW.SoDdGbzplIDm8bfSUcbFm', 'CLIENT', 'VERIFIED')
ON CONFLICT (email) DO NOTHING;

INSERT INTO accounts (user_id, kind, currency)
SELECT u.id, 'USER', 'CACAO'
FROM users u
WHERE u.email IN ('admin@bancocacao.pe', 'vendedor@bancocacao.pe', 'cliente@bancocacao.pe')
ON CONFLICT (user_id, currency) DO NOTHING;

INSERT INTO products (name, description, cacao_price, stock, seller_id)
SELECT
    p.name, p.description, p.cacao_price, p.stock,
    (SELECT id FROM users WHERE email = 'vendedor@bancocacao.pe')
FROM (VALUES
    ('Bebida de cacao ceremonial', 'Bebida caliente a base de cacao puro de la selva peruana', 25, 40),
    ('Tableta de cacao 70%',       'Chocolate artesanal 70% cacao',                              40, 25),
    ('Nibs de cacao tostados',     'Granos de cacao tostado y troceado, 100 g',                  15, 60),
    ('Manteca de cacao',           'Manteca de cacao pura para repostería',                      35, 20)
) AS p(name, description, cacao_price, stock)
WHERE NOT EXISTS (SELECT 1 FROM products);

COMMIT;
