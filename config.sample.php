<?php
// Founders Walk settings. Copy this file to config.php and fill it in.
// config.php is blocked from the web by .htaccess; never commit the real one.

// Host PIN: what you type on the host screen (?host). Use 8+ characters.
const FW_HOST_PIN = 'change-me';

// Who summary emails come from. Create this mailbox in hPanel > Emails first,
// so Hostinger signs the mail and it lands in inboxes rather than spam.
const FW_FROM_ADDRESS = 'walk@uncommoncollectiveau.com';
const FW_FROM_NAME    = 'Uncommon Collective';

// Where replies and the host roll-up go.
const FW_HOST_EMAIL = 'contact@uncommoncollectiveau.com';

// 'mail' sends real email with PHP mail(). 'log' writes each email to
// data/outbox/ instead, for testing.
const FW_MAIL_MODE = 'mail';

// Link shown at the end and in emails, for the next walk.
const FW_NEXT_WALK_URL = 'https://luma.com/uncommoncollective';

// Walks (names, emails, links) are deleted this many days after they start.
const FW_RETENTION_DAYS = 30;

// SQLite database file. The data/ folder is blocked from the web.
const FW_DB_PATH = __DIR__ . '/data/founders-walk.sqlite';
