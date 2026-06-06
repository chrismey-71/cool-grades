#!/usr/bin/env node

const fs = require('node:fs/promises');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

let chromium;
try {
  ({ chromium } = require('playwright'));
} catch (error) {
  throw new Error('Playwright ist nicht installiert. Bitte im Projektumfeld `npm install playwright` ausführen oder Playwright global bereitstellen.');
}

const ROOT = path.resolve(__dirname, '..');
const BASE_URL = process.env.HANDBUCH_BASE_URL || 'http://127.0.0.1:8044';
const OUTPUT_DIR = path.join(ROOT, 'docs', 'screenshots', 'lehrerhandbuch');
const DEMO_SETUP_SCRIPT = path.join(ROOT, 'scripts', 'setup_lehrerhandbuch_demo.php');
const DEMO_START_SCRIPT = path.join(ROOT, 'scripts', 'start_lehrerhandbuch_demo.sh');

const DEMO = {
  username: 'lehrer.demo',
  password: 'DemoLehrer123!',
  classId: 1,
  amSubjectId: 1,
  deutschSubjectId: 2,
  schoolPeriodSetId: 1,
  criteriaSetId: 1,
  firstStudentId: 1,
  firstPresetId: 1,
  groupClassId: 1,
  groupSubjectId: 1,
  participationEditId: 1,
};

function runChecked(command, args, options = {}) {
  const result = spawnSync(command, args, {
    cwd: ROOT,
    encoding: 'utf-8',
    stdio: 'pipe',
    ...options,
  });

  if (result.status !== 0) {
    const stderr = (result.stderr || '').trim();
    const stdout = (result.stdout || '').trim();
    const detail = stderr || stdout || `Exit-Code ${result.status}`;
    throw new Error(`${command} ${args.join(' ')} fehlgeschlagen: ${detail}`);
  }
  return result;
}

async function ensureDir(dir) {
  await fs.mkdir(dir, { recursive: true });
}

async function ensureDemoData() {
  runChecked('bash', [DEMO_START_SCRIPT]);
  runChecked('php', [DEMO_SETUP_SCRIPT]);
}

async function assertAppReachable(page) {
  const response = await page.goto(`${BASE_URL}/login.php`, { waitUntil: 'domcontentloaded' });
  if (!response) {
    throw new Error(`Keine Antwort von ${BASE_URL}/login.php erhalten.`);
  }
  if (![200, 302].includes(response.status())) {
    throw new Error(`Demo-App antwortet unerwartet mit HTTP ${response.status()}.`);
  }
}

async function login(page) {
  await assertAppReachable(page);
  await page.fill('input[name="username"]', DEMO.username);
  await page.fill('input[name="password"]', DEMO.password);
  await Promise.all([
    page.waitForURL(/dashboard\.php/),
    page.click('button:has-text("Anmelden")'),
  ]);
}

async function saveShot(pageOrLocator, filename, options = {}) {
  const filePath = path.join(OUTPUT_DIR, filename);
  await ensureDir(path.dirname(filePath));
  await pageOrLocator.screenshot({
    path: filePath,
    ...options,
  });
  return filePath;
}

async function openSection(page, summaryText) {
  const summary = page.locator(`summary:has-text("${summaryText}")`).first();
  if (await summary.count() === 0) return;
  const details = summary.locator('xpath=ancestor::details[1]');
  const isOpen = await details.evaluate((node) => node.hasAttribute('open'));
  if (!isOpen) {
    await summary.click();
  }
}

async function captureDashboard(page) {
  await page.goto(`${BASE_URL}/teacher/index.php`, { waitUntil: 'networkidle' });
  await saveShot(page, '01-dashboard.png');
}

async function captureAccount(page) {
  await page.goto(`${BASE_URL}/account.php`, { waitUntil: 'networkidle' });
  await saveShot(page, '02-kontoeinstellungen.png');
  await saveShot(page, '03-theme-einstellungen.png');
}

async function captureParticipationNew(page) {
  await page.goto(`${BASE_URL}/teacher/participation_new.php?class_id=${DEMO.classId}&subject_id=${DEMO.amSubjectId}`, { waitUntil: 'networkidle' });
  await saveShot(page, '04-schnell-mitarbeit-leer.png');

  await openSection(page, 'Preset');
  const presetSelect = page.locator('select[name="preset_id"]');
  if (await presetSelect.count()) {
    await presetSelect.selectOption(String(DEMO.firstPresetId));
    await page.waitForLoadState('networkidle');
  }
  await saveShot(page, '15-preset-anwenden.png');

  await page.selectOption('select[name="reason_option_id"]', { label: 'Diskussion' });
  await page.selectOption('select[name="impact_option_id"]', { label: 'positiv (+)' });
  const performanceCheckbox = page.locator('input[name="performance_option_ids[]"]').first();
  if (!(await performanceCheckbox.isChecked())) {
    await performanceCheckbox.check();
  }
  const observationCheckbox = page.locator('input[name="group_option_ids[]"]').first();
  if (!(await observationCheckbox.isChecked())) {
    await observationCheckbox.check();
  }
  await page.fill('textarea[name="reason_text"]', 'Erklärt den Lösungsweg klar und fachsprachlich sicher.');
  await page.fill('textarea[name="note"]', 'Geeignet als kurzes Beispiel für positive Mitarbeit mit nachvollziehbarer Fachbeobachtung.');
  await page.check(`input[name="student_ids[]"][value="${DEMO.firstStudentId}"]`);
  await saveShot(page, '05-schnell-mitarbeit-ausgefueellt.png');

  await Promise.all([
    page.waitForLoadState('networkidle'),
    page.click('button[name="action"][value="save_entry"]'),
  ]);
  await saveShot(page, '06-mitarbeit-gespeichert.png');
}

async function captureOral(page) {
  await page.goto(`${BASE_URL}/teacher/oral_new.php?class_id=${DEMO.classId}&subject_id=${DEMO.amSubjectId}&oral_type=ORAL_EXERCISE`, { waitUntil: 'networkidle' });
  await openSection(page, 'Details zur Leistungsfeststellung');
  await page.selectOption('select[name="student_id"]', String(DEMO.firstStudentId));
  await page.fill('input[name="category"]', 'Präsentation');
  await page.fill('input[name="title"]', 'Kurzreferat zum Zinseszins');
  await saveShot(page, '07-besondere-muendliche-leistung.png');
}

async function captureExam(page) {
  await page.goto(`${BASE_URL}/teacher/exam_new.php?class_id=${DEMO.classId}&subject_id=${DEMO.amSubjectId}`, { waitUntil: 'networkidle' });
  await page.selectOption('select[name="exam_type"]', 'TASK');
  await page.fill('input[name="title"]', 'Schriftlicher Arbeitsauftrag – Prozentrechnung');
  await page.fill('input[name="exam_date"]', '2026-05-06');
  await saveShot(page, '08-besondere-schriftliche-leistung.png');
}

async function captureParticipationList(page) {
  const url = `${BASE_URL}/teacher/participation_list.php?class_id=${DEMO.classId}&subject_id=${DEMO.amSubjectId}&from=2026-02-01&to=2026-05-10`;
  await page.goto(url, { waitUntil: 'networkidle' });
  await saveShot(page, '09-eintragsliste.png');
  const filterCard = page.locator('form[method="get"]').first();
  await saveShot(filterCard, '10-eintragsfilter.png');
}

async function captureParticipationEdit(page) {
  await page.goto(`${BASE_URL}/teacher/participation_edit.php?id=${DEMO.participationEditId}`, { waitUntil: 'networkidle' });
  await saveShot(page, '11-eintrag-bearbeiten.png');
}

async function captureCriteria(page) {
  await page.goto(`${BASE_URL}/teacher/criteria.php?set=${DEMO.criteriaSetId}`, { waitUntil: 'networkidle' });
  await saveShot(page, '12-kriterienverwaltung.png');
}

async function captureOptions(page) {
  await page.goto(`${BASE_URL}/teacher/options.php?type=reason&subject_id=${DEMO.amSubjectId}`, { waitUntil: 'networkidle' });
  await saveShot(page, '13-picklistenverwaltung.png');
}

async function capturePresets(page) {
  await page.goto(`${BASE_URL}/teacher/presets.php?preset=${DEMO.firstPresetId}`, { waitUntil: 'networkidle' });
  await saveShot(page, '14-presets-verwalten.png');
}

async function captureGroups(page) {
  await page.goto(`${BASE_URL}/teacher/student_groups.php?class_id=${DEMO.groupClassId}&subject_id=${DEMO.groupSubjectId}`, { waitUntil: 'networkidle' });
  await openSection(page, 'Gruppe manuell anlegen');
  await openSection(page, 'Random-Zuordnung');
  await saveShot(page, '16-gruppenverwaltung.png');
}

async function captureReports(page) {
  const classSummaryUrl = `${BASE_URL}/reports.php?class_id=${DEMO.classId}&subject_id=${DEMO.amSubjectId}&period=semester2`;
  await page.goto(classSummaryUrl, { waitUntil: 'networkidle' });
  const reportFilter = page.locator('form[method="get"]').first();
  await saveShot(reportFilter, '18-auswertung-filter.png');
  await saveShot(page, '19-auswertung-tabelle.png');
  await saveShot(page, '17-fachstatus-auswertung.png');
  await saveShot(page, '21-pdf-export-schaltflaeche.png');

  await page.goto(`${classSummaryUrl}&student_id=${DEMO.firstStudentId}`, { waitUntil: 'networkidle' });
  await saveShot(page, '20-auswertung-detail.png');
}

async function capturePdfExample(page, context) {
  const reportUrl = `${BASE_URL}/reports.php?class_id=${DEMO.classId}&subject_id=${DEMO.amSubjectId}&period=semester2&student_id=${DEMO.firstStudentId}`;
  await page.goto(reportUrl, { waitUntil: 'networkidle' });

  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.click('a:has-text("PDF herunterladen")'),
  ]);
  const pdfPath = path.join(OUTPUT_DIR, 'example-report.pdf');
  await download.saveAs(pdfPath);
  const pdfPreview = path.join(OUTPUT_DIR, '22-beispiel-pdf-auswertung.png');
  runChecked('/usr/local/bin/gs', [
    '-dSAFER',
    '-dBATCH',
    '-dNOPAUSE',
    '-sDEVICE=png16m',
    '-r160',
    `-sOutputFile=${pdfPreview}`,
    pdfPath,
  ]);
  await fs.copyFile(pdfPreview, path.join(OUTPUT_DIR, '23-pdf-export-ergebnis.png'));
}

async function captureFinalAssessments(page) {
  const semesterUrl = `${BASE_URL}/teacher/final_assessments.php?class_id=${DEMO.classId}&subject_id=${DEMO.amSubjectId}&school_period_set_id=${DEMO.schoolPeriodSetId}&scope=semester2`;
  await page.goto(semesterUrl, { waitUntil: 'networkidle' });

  await page.selectOption(`form#fa-single-form-${DEMO.firstStudentId} select[name="final_grade"]`, '2');
  await page.fill(`form#fa-single-form-${DEMO.firstStudentId} textarea[name="teacher_comment"]`, 'Die dokumentierte Mitarbeit ist über den Zeitraum hinweg stabil positiv und fachlich tragfähig.');
  await Promise.all([
    page.waitForLoadState('networkidle'),
    page.click(`form#fa-single-form-${DEMO.firstStudentId} button[value="final"]`),
  ]);
  await saveShot(page, '24-abschlussbeurteilung-uebersicht.png');

  const yearUrl = `${BASE_URL}/teacher/final_assessments.php?class_id=${DEMO.classId}&subject_id=${DEMO.amSubjectId}&school_period_set_id=${DEMO.schoolPeriodSetId}&scope=year`;
  await page.goto(yearUrl, { waitUntil: 'networkidle' });
  await saveShot(page, '25-abschlussbeurteilung-details.png');

  await page.goto(semesterUrl, { waitUntil: 'networkidle' });
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.click('a:has-text("Bericht / PDF")'),
  ]);
  const pdfPath = path.join(OUTPUT_DIR, 'final-assessment-report.pdf');
  await download.saveAs(pdfPath);
  const pdfPagePattern = path.join(OUTPUT_DIR, 'final-assessment-pdf-%d.png');
  runChecked('/usr/local/bin/gs', [
    '-dSAFER',
    '-dBATCH',
    '-dNOPAUSE',
    '-sDEVICE=png16m',
    '-r160',
    `-sOutputFile=${pdfPagePattern}`,
    pdfPath,
  ]);
  try {
    await fs.copyFile(path.join(OUTPUT_DIR, 'final-assessment-pdf-2.png'), path.join(OUTPUT_DIR, '26-abschlussbeurteilung-pdf.png'));
  } catch (_error) {
    await fs.copyFile(path.join(OUTPUT_DIR, 'final-assessment-pdf-1.png'), path.join(OUTPUT_DIR, '26-abschlussbeurteilung-pdf.png'));
  }
}

async function main() {
  await ensureDir(OUTPUT_DIR);
  await ensureDemoData();

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 2200 },
    colorScheme: 'light',
    locale: 'de-AT',
  });
  const page = await context.newPage();
  page.on('dialog', async (dialog) => {
    await dialog.accept();
  });

  try {
    await login(page);
    await captureDashboard(page);
    await captureAccount(page);
    await captureParticipationNew(page);
    await captureOral(page);
    await captureExam(page);
    await captureParticipationList(page);
    await captureParticipationEdit(page);
    await captureCriteria(page);
    await captureOptions(page);
    await capturePresets(page);
    await captureGroups(page);
    await captureReports(page);
    await capturePdfExample(page, context);
    await captureFinalAssessments(page);
  } finally {
    await browser.close();
  }

  console.log(`Screenshots erzeugt in ${OUTPUT_DIR}`);
}

main().catch((error) => {
  console.error(error.stack || String(error));
  process.exit(1);
});
