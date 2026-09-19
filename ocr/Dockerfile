# Texterkennung für Anhänge (dienst.py). Tesseract mit deutschen und englischen Sprachdaten, Poppler für PDFs,
# Python nur mit Standardbibliothek. Getrennt vom BookStack-Image, damit dessen Updates unberührt bleiben.
FROM debian:13-slim

RUN apt-get update \
 && apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-deu tesseract-ocr-eng poppler-utils python3 \
 && rm -rf /var/lib/apt/lists/* \
 && useradd --system --uid 10001 --no-create-home ocr

COPY dienst.py probe.py /app/

USER ocr
# Tesseract rechnet sonst mit allen Kernen pro Seite; Parallelität regelt OCR_PARALLEL.
# Das Dateisystem ist schreibgeschützt: Fontconfig (Poppler) legt seinen Cache in /tmp an.
ENV OMP_THREAD_LIMIT=1 PYTHONUNBUFFERED=1 XDG_CACHE_HOME=/tmp
EXPOSE 8080
CMD ["python3", "/app/dienst.py"]
