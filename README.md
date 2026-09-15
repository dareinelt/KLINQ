# Assetverwaltung

Webbasierte Inventar- und Assetverwaltung für IT-Hardware (PCs, Mobilgeräte, Netzwerkkomponenten, Zubehör) mit
QR-Etiketten, mobiler Erfassung (PWA, offlinefähig), Active-Directory-Anbindung, Einkauf/Wareneingang, Lizenzen,
Historie, Audit-Log und rollenbasierter Benutzerverwaltung.

**Technologie:** PHP 8.4, MySQL 8.4, Vanilla JavaScript, HTML, CSS – ohne Frameworks, ohne CDNs, ohne externe Laufzeitabhängigkeiten.
Betrieb vollständig in Docker; ein separater Python-Container (WeasyPrint) erzeugt die PDF-Archive der Übergabeprotokolle.

![Dashboard](docs/screenshots/dashboard.png)

## Schnellstart

```bash
cp .env.example .env          # Passwörter anpassen (DB_PASSWORD, DB_ROOT_PASSWORD, ADMIN_PASSWORD)
docker compose up --build -d  # Migrationen laufen automatisch beim Start
```

Anwendung: <http://localhost:8080> – Anmeldung mit `ADMIN_USERNAME` / `ADMIN_PASSWORD` aus der `.env`.

## Dokumentation

- [Installation & Betrieb](docs/installation.md)
- [Architektur](docs/architektur.md)
- [Datenmodell](docs/datenmodell.md)
- [AD-Synchronisation](docs/ad-sync.md)
- [Assets & Inventarnummern](docs/assets.md)
- [Etiketten & QR-Codes](docs/labels.md)
- [Bestandsmanagement – Entnahme & Retoure](docs/movements.md)
- [Einkauf – Bestellungen & Wareneingang](docs/einkauf.md)
- [Lizenzen – Verwaltung & Assetzuordnung](docs/lizenzen.md)
- [Übergabeprotokoll – Artikelstamm, digitale Unterschrift & Vorlagen-Baukasten](docs/uebergabeprotokoll.md)
- [Offline-Betrieb – PWA, Warteschlange & Synchronisation](docs/offline.md)
- [Berichte & Dashboard – Auswertungen & CSV-Export](docs/berichte.md)
- [Import – Altbestand aus CSV übernehmen](docs/import.md)
- [Audit-Log & Benutzerverwaltung](docs/audit.md)
- [Help Desk – Ticketsystem, Serviceportal & Wissensdatenbank](docs/helpdesk.md)
- [Backup & Wiederherstellung](docs/backup.md)

Die gesamte Dokumentation gibt es zusätzlich als PDF mit Inhaltsverzeichnis: [docs/handbuch.pdf](docs/handbuch.pdf).
Neu erzeugen nach Änderungen an den Markdown-Dateien:

```bash
pip install weasyprint==63.1 markdown   # nur für den Build nötig
python3 bin/build-docs-pdf.py
```

## Tests

```bash
php tests/run.php --unit                 # Unit-Tests (ohne Datenbank)
docker compose exec app php tests/run.php # alle Tests inkl. Integration (Test-Datenbank)
```
