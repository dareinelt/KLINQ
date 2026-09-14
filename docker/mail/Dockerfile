FROM python:3.12-slim

# Nur Python-Standardbibliothek (smtplib/email) – keine zusätzlichen Pakete nötig.
RUN useradd --system --create-home --uid 10002 mail
WORKDIR /app
COPY docker/mail/server.py /app/server.py
COPY docker/mail/healthcheck.py /app/healthcheck.py
USER mail

EXPOSE 8025
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD ["python", "/app/healthcheck.py"]
CMD ["python", "/app/server.py"]
