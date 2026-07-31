/*
 * Measure real horizontal overflow at phone widths.
 *
 * Reading Tailwind classes cannot tell you whether a page overflows — this found
 * a bug that class inspection missed entirely: the flex content column had no
 * `min-w-0`, so it grew to the widest table and pushed the page sideways by up to
 * 706px, while the `overflow-x-auto` wrappers sat unused.
 *
 * Needs a running app and a login. Do NOT point it at production — it signs in.
 *
 *   php spark serve --port 8899 &
 *   # create a throwaway admin, then:
 *   PROBE_EMAIL=you@example.com PROBE_PASSWORD=secret node scripts/mobile-audit.js
 *
 * "PAGE OVERFLOW" is a bug: the page itself scrolls sideways.
 * "~ needs Npx in Mpx" is a table scrolling inside its own wrapper — intended for
 * wide admin tables, worth fixing on employee-facing screens.
 */

const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');

const BASE = process.env.PROBE_BASE || 'http://127.0.0.1:8899';
const PAGES = [
  '/dashboard', '/attendance', '/attendance/report', '/requests',
  '/admin/dashboard', '/admin/attendance', '/admin/attendance/today',
  '/admin/attendance/report', '/admin/requests', '/admin/users',
  '/admin/access-logs', '/admin/settings', '/account', '/notifications',
];
const VIEWPORTS = [{ w: 320, h: 568 }, { w: 375, h: 667 }];

(async () => {
  const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome' });
  const ctx = await browser.newContext({ viewport: { width: 375, height: 667 }, ignoreHTTPSErrors: true });
  const page = await ctx.newPage();

  // log in
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.fill('#email', process.env.PROBE_EMAIL || 'mobile-probe@example.com');
  await page.fill('#password', process.env.PROBE_PASSWORD || 'Probe!Pass123');
  await page.click('button[type=submit]');
  await page.waitForURL(/dashboard/, { timeout: 15000 });
  console.log('logged in\n');

  for (const vp of VIEWPORTS) {
    await page.setViewportSize({ width: vp.w, height: vp.h });
    console.log(`=========== viewport ${vp.w}x${vp.h}`);

    for (const path of PAGES) {
      const res = await page.goto(BASE + path, { waitUntil: 'networkidle' }).catch(e => null);
      if (!res || res.status() >= 400) { console.log(`  ${path.padEnd(30)} HTTP ${res ? res.status() : 'ERR'}`); continue; }
      await page.waitForTimeout(120);

      const report = await page.evaluate(() => {
        const de = document.documentElement;
        const overflow = de.scrollWidth - de.clientWidth;
        const culprits = [];
        if (overflow > 1) {
          for (const el of document.querySelectorAll('body *')) {
            const r = el.getBoundingClientRect();
            if (r.width === 0 || r.height === 0) continue;
            if (r.right > de.clientWidth + 1) {
              // only report the outermost offenders
              culprits.push({
                tag: el.tagName.toLowerCase(),
                cls: (el.className || '').toString().slice(0, 70),
                right: Math.round(r.right),
                width: Math.round(r.width),
              });
            }
          }
        }
        // tables that need sideways scrolling inside their own wrapper
        const scrollers = [...document.querySelectorAll('*')]
          .filter(el => el.scrollWidth > el.clientWidth + 4 && getComputedStyle(el).overflowX !== 'visible')
          .map(el => ({ cls: (el.className || '').toString().slice(0, 50), need: el.scrollWidth, have: el.clientWidth }));
        return { overflow, culprits: culprits.slice(0, 4), scrollers: scrollers.slice(0, 3) };
      });

      const flag = report.overflow > 1 ? `PAGE OVERFLOW +${report.overflow}px` : 'ok';
      console.log(`  ${path.padEnd(30)} ${flag}`);
      report.culprits.forEach(c => console.log(`      ! <${c.tag}> w=${c.width} right=${c.right}  ${c.cls}`));
      report.scrollers.forEach(s => console.log(`      ~ needs ${s.need}px in ${s.have}px  ${s.cls}`));
    }
    console.log('');
  }

  await browser.close();
})();
