// Findet Chrome oder Chromium für die Skripte, die headless rendern (Rauchtest, Handbuch-Bilder, Icon, PDF).
// Reihenfolge: Umgebungsvariable CHROME, dann die üblichen Installationsorte unter Windows, macOS und Linux.
import fs from 'node:fs';
import path from 'node:path';

const KANDIDATEN = {
  win32: [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    path.join(process.env.LOCALAPPDATA || '', 'Google/Chrome/Application/chrome.exe'),
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ],
  darwin: [
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/Applications/Chromium.app/Contents/MacOS/Chromium',
  ],
  linux: [
    '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/snap/bin/chromium',
  ],
};

export function chromePfad() {
  if (process.env.CHROME) return process.env.CHROME;
  const gefunden = (KANDIDATEN[process.platform] || []).find((p) => p && fs.existsSync(p));
  if (gefunden) return gefunden;
  console.error('Chrome nicht gefunden. Pfad in der Umgebungsvariable CHROME angeben, z. B. CHROME=/usr/bin/chromium');
  process.exit(2);
}
