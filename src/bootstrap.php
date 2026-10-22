<?php

declare(strict_types=1);

require __DIR__ . '/AppException.php';
require __DIR__ . '/Config.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Response.php';
require __DIR__ . '/Jwt.php';
require __DIR__ . '/Http.php';
require __DIR__ . '/Ledger.php';
require __DIR__ . '/Tx.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/Wallet.php';
require __DIR__ . '/Products.php';
require __DIR__ . '/Stp.php';
require __DIR__ . '/Investments.php';
require __DIR__ . '/Loans.php';

Config::load(__DIR__ . '/../.env');

error_reporting(E_ALL);
ini_set('display_errors', Config::bool('APP_DEBUG', false) ? '1' : '0');
ini_set('log_errors', '1');
