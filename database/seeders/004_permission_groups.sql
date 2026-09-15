-- Berechtigungsgruppen (idempotent). Zusätzlich zur Rolle zuweisbare Rechte;
-- „Statistik“ gewährt Zugriff auf die Help-Desk-Ticket-Berichte (siehe config/permissions.php).
INSERT INTO permission_groups (name, label) VALUES
    ('statistik', 'Statistik')
ON DUPLICATE KEY UPDATE label = VALUES(label);
