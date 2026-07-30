import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
import { readFileSync } from 'node:fs';

const scoring = JSON.parse(
  readFileSync(fileURLToPath(new URL('./data/scoring.json', import.meta.url)), 'utf-8')
);

/**
 * Dev-only stand-in for the real REST endpoints (WP_REST_Server::submit /
 * results). There is no WordPress in `npm run dev`, so this fakes just enough
 * of the response shape for Survey.jsx / Results.jsx to render against -
 * never included in `npm run build`.
 */
function mockApiPlugin() {
  return {
    name: 'brs-mock-api',
    apply: 'serve',
    configureServer(server) {
      server.middlewares.use(async (req, res, next) => {
        if (req.method === 'POST' && req.url === '/mock-submit') {
          await readJson(req); // drain the body; answers aren't used by the mock
          sendJson(res, 201, { ok: true, reference: randomReference() });
          return;
        }

        const match = req.method === 'GET' && req.url.match(/^\/mock-results\/([^/?]+)/);

        if (match) {
          sendJson(res, 200, fakeResults(decodeURIComponent(match[1])));
          return;
        }

        next();
      });
    },
  };
}

function readJson(req) {
  return new Promise((resolve) => {
    let body = '';
    req.on('data', (chunk) => (body += chunk));
    req.on('end', () => {
      try {
        resolve(JSON.parse(body || '{}'));
      } catch {
        resolve({});
      }
    });
  });
}

function sendJson(res, status, payload) {
  res.statusCode = status;
  res.setHeader('Content-Type', 'application/json');
  res.end(JSON.stringify(payload));
}

function randomReference() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  const part = () => Array.from({ length: 5 }, () => chars[Math.floor(Math.random() * chars.length)]).join('');
  return `${part()}-${part()}`;
}

// Any reference string, typed or freshly submitted, hashes to the same fake
// result on repeat visits - so reloading (or the "view results again" flow)
// doesn't reshuffle the dashboard under the same code.
function fakeResults(reference) {
  const rand = mulberry32(seedFrom(reference));
  const levels = scoring.maturityLevels;
  const levelIndex = Math.floor(rand() * levels.length);

  // The real endpoint returns every scored item with feedback for the
  // answer given, not a capped top-N - a low-scoring respondent can end up
  // with a long list, grouped client-side by the item's section. Mocked the
  // same way: worse bands get more "gaps", spread across the real sections.
  const actionPool = [
    { id: 'q9', label: 'Incident Command Structure', section: 'Critical Incident Management Capability', feedback: 'Formalise your incident command structure with clearly assigned roles.' },
    { id: 'q16b', label: 'Lockdown Drills', section: 'Emergency Management Preparedness', feedback: 'Schedule a full-scale lockdown drill at least once per term.' },
    { id: 'q23', label: 'Business Continuity Plan', section: 'Business Continuity and Recovery', feedback: 'Document a business continuity plan covering your top three critical functions.' },
    { id: 'q31', label: 'Parent Notification Channel', section: 'Communications Readiness', feedback: 'Set up a pre-approved parent/caregiver mass notification channel.' },
    { id: 'q40', label: 'Post-Incident Review', section: 'Critical Incident Management Capability', feedback: 'Establish a post-incident review process to capture lessons learned.' },
    { id: 'q12', label: 'Leadership Tabletop Exercises', section: 'Governance and Leadership', feedback: 'Run a tabletop exercise with senior leadership at least annually.' },
    { id: 'q18c', label: 'Evacuation Routes', section: 'Emergency Management Preparedness', feedback: 'Publish evacuation routes and assembly points in every classroom.' },
    { id: 'q22', label: 'Backup Power', section: 'Business Continuity and Recovery', feedback: 'Confirm backup power arrangements for critical systems.' },
    { id: 'q27b', label: 'Incident Role Deputies', section: 'Critical Incident Management Capability', feedback: 'Assign a trained deputy for each critical incident role.' },
    { id: 'q34', label: 'Mass Notification Testing', section: 'Communications Readiness', feedback: 'Test the mass notification system at least once per semester.' },
    { id: 'q38', label: 'Plan Review Cadence', section: 'Business Continuity and Recovery', feedback: 'Review and update the business continuity plan after every drill.' },
    { id: 'q44', label: 'Staff Feedback Loop', section: 'Governance and Leadership', feedback: 'Capture staff feedback after incidents and feed it into training.' },
  ];

  const count = Math.max(1, actionPool.length - levelIndex * 2 - Math.floor(rand() * 2));

  // Matches data/scoring.json's sections order - the real endpoint orders
  // actions by this, then by gap within each section.
  const sectionOrder = [
    'Governance and Leadership',
    'Emergency Management Preparedness',
    'Critical Incident Management Capability',
    'Business Continuity and Recovery',
    'Communications Readiness',
  ];

  return {
    reference,
    schoolName: 'Sample School (dev data)',
    submittedAt: new Date().toISOString().slice(0, 19).replace('T', ' '),
    score: { band: { level: levels[levelIndex].level } },
    maturityLevels: levels.map((l) => ({ level: l.level, label: l.label })),
    actions: shuffle(actionPool, rand)
      .slice(0, count)
      .sort((a, b) => sectionOrder.indexOf(a.section) - sectionOrder.indexOf(b.section))
      .map((a) => ({ id: a.id, label: a.label, section: a.section, feedback: a.feedback })),
    peers: {
      sampleSize: 12,
      meanPosition: 1.5 + rand() * 2.5,
      myPosition: levelIndex + rand(),
      stdevPosition: 0.6,
    },
  };
}

function seedFrom(str) {
  let h = 0;
  for (let i = 0; i < str.length; i++) h = (h * 31 + str.charCodeAt(i)) >>> 0;
  return h;
}

function mulberry32(a) {
  return function () {
    a |= 0;
    a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

function shuffle(arr, rand) {
  const a = [...arr];
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(rand() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a;
}

export default defineConfig({
  plugins: [react(), mockApiPlugin()],
  build: {
    outDir: 'build',
    emptyOutDir: true,
    // Single CSS file rather than injected chunks — WordPress enqueues it separately.
    cssCodeSplit: false,
    rollupOptions: {
      // Absolute path. A bare relative path resolves inconsistently on Windows.
      // This also means the build ignores index.html, which is dev-only.
      input: fileURLToPath(new URL('./src/main.jsx', import.meta.url)),
      output: {
        // IIFE, not ES modules. wp_enqueue_script outputs a classic <script> tag,
        // so an ES module build would fail silently until the tag is patched.
        format: 'iife',
        entryFileNames: 'survey.js',
        assetFileNames: 'survey.[ext]',
      },
    },
  },
});
