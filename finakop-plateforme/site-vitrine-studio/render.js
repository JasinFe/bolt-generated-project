/* Rendu image par image des scènes CSS en MP4 (H.264) ou en images fixes.
 *
 *   node render.js video <scene.html> <sortie.mp4> <durée_s> [fps=30] [échelle=1.25]
 *   node render.js images <scene.html> <dossier> <t1,t2,…> [échelle]
 *
 * Variables : PLAYWRIGHT (module), FFMPEG (binaire avec libx264).
 * Chaque image fixe currentTime = t sur toutes les animations : rendu déterministe,
 * indépendant de la vitesse de la machine.
 */
const path = require('path');
const fs = require('fs');
const { spawn } = require('child_process');
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');

(async () => {
  const [mode, scene, sortie, arg, a5, a6] = process.argv.slice(2);
  const echelle = parseFloat((mode === 'video' ? a6 : a5) || '1.25');
  const navigateur = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });
  const page = await navigateur.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: echelle });
  await page.goto('file://' + path.resolve(scene));
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(300);
  const fixer = (t) => page.evaluate((ms) => {
    document.getAnimations().forEach((a) => { a.pause(); a.currentTime = ms; });
    document.querySelectorAll('svg').forEach((s) => { if (s.pauseAnimations) { s.pauseAnimations(); s.setCurrentTime(ms / 1000); } });
  }, t * 1000);

  if (mode === 'images') {
    fs.mkdirSync(sortie, { recursive: true });
    for (const t of arg.split(',').map(Number)) {
      await fixer(t);
      await page.screenshot({ path: path.join(sortie, `image-${t}.jpg`), type: 'jpeg', quality: 92 });
    }
  } else {
    const duree = parseFloat(arg), fps = parseInt(a5 || '30', 10), n = Math.round(duree * fps);
    const ff = spawn(process.env.FFMPEG || 'ffmpeg', ['-y', '-loglevel', 'error', '-f', 'image2pipe', '-framerate', String(fps), '-i', '-',
      '-c:v', 'libx264', '-preset', 'slow', '-crf', '23', '-pix_fmt', 'yuv420p', '-vf', 'scale=trunc(iw/2)*2:trunc(ih/2)*2',
      '-movflags', '+faststart', sortie], { stdio: ['pipe', 'inherit', 'inherit'] });
    for (let i = 0; i < n; i++) {
      await fixer(i / fps);
      const img = await page.screenshot({ type: 'jpeg', quality: 90 });
      if (!ff.stdin.write(img)) await new Promise((r) => ff.stdin.once('drain', r));
      if (i % (fps * 5) === 0) process.stdout.write(`  ${Math.round(i / fps)} s / ${duree} s\n`);
    }
    ff.stdin.end();
    await new Promise((r, j) => ff.on('close', (c) => (c === 0 ? r() : j(new Error('ffmpeg ' + c)))));
  }
  await navigateur.close();
})().catch((e) => { console.error(e); process.exit(1); });
