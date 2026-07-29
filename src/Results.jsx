import { useEffect, useLayoutEffect, useRef, useState } from 'react';

// Ordinal risk strip: red -> orange -> yellow -> green -> green. A 5-step
// same-direction ramp packed this tightly cannot clear full CVD separation on
// its own (checked against the project's palette validator) - every segment
// therefore always carries its own visible text label (never color alone),
// which is the documented mitigation for that failure mode.
const LEVEL_COLORS = ['#b13232', '#c96f4d', '#c98900', '#769c28', '#0a8b0a'];
const LEVEL_TEXT = ['#ffffff', '#ffffff', '#2f2400', '#123300', '#ffffff'];

// Fixed two-series order (peer average, your score) - matches the
// project's validated categorical slots 1 (blue) and 2 (orange).
const PEER_COLOR = '#2a78d6';
const YOU_COLOR = '#eb6834';

/**
 * A small standalone form for "I already submitted, let me see my results
 * again" - used on the survey's landing step, before/instead of starting a
 * new response.
 */
export function ReferenceLookupForm({ onSubmit }) {
  const [value, setValue] = useState('');

  function submit(e) {
    e.preventDefault();
    const ref = value.trim();
    if (ref) onSubmit(ref);
  }

  return (
    <form onSubmit={submit} className="brs-r-lookup-form">
      <input
        type="text"
        className="brs-r-input"
        placeholder="XXXXX-XXXXX"
        value={value}
        onChange={(e) => setValue(e.target.value)}
        aria-label="Reference number"
      />
      <button type="submit" className="brs-r-btn">
        View results
      </button>
    </form>
  );
}

// The mock/real fetch can resolve near-instantly - holding the loading state
// open for at least this long keeps the calculating animation from flashing.
const MIN_LOADING_MS = 1600;

const LOADING_STEPS = [
  'Scoring your responses…',
  'Rating against best practice…',
  'Comparing you to peer schools…',
  'Preparing your recommendations…',
];

/**
 * Renders the results dashboard for a given reference. Fetching (rather than
 * the caller passing the data down) keeps this self-contained: the submit
 * response only carries the bare reference, and a returning visitor arrives
 * with nothing but the reference they typed into ReferenceLookupForm.
 */
export default function Results({ reference, restUrlBase, onBack }) {
  const [state, setState] = useState({ status: 'loading', data: null, error: null });

  useEffect(() => {
    setState({ status: 'loading', data: null, error: null });

    const url = restUrlBase.endsWith('/') ? restUrlBase + encodeURIComponent(reference) : restUrlBase + '/' + encodeURIComponent(reference);
    const started = Date.now();

    // Settle the state only after both the fetch and the minimum display
    // window have finished, so the calculating animation always gets seen.
    const settle = (next) => {
      const remaining = MIN_LOADING_MS - (Date.now() - started);
      if (remaining > 0) {
        setTimeout(() => setState(next), remaining);
      } else {
        setState(next);
      }
    };

    fetch(url)
      .then(async (res) => {
        const body = await res.json().catch(() => null);
        if (!res.ok) throw new Error(body?.message || 'We could not find results for that reference.');
        return body;
      })
      .then((data) => settle({ status: 'loaded', data, error: null }))
      .catch((err) => settle({ status: 'error', data: null, error: err.message }));
  }, [reference, restUrlBase]);

  if (state.status === 'error') {
    return (
      <div className="brs-results">
        <div className="brs-r-card brs-r-lookup">
          <p className="brs-r-error">{state.error}</p>
          <button type="button" className="brs-r-btn brs-r-btn--ghost" onClick={onBack}>
            Back
          </button>
        </div>
      </div>
    );
  }

  if (state.status === 'loading') {
    return (
      <div className="brs-results">
        <ResultsLoading />
      </div>
    );
  }

  const { data } = state;

  return (
    <div className="brs-results">
      <Header data={data} onBack={onBack} />
      <BestPractice score={data.score} levels={data.maturityLevels} />
      <Peers peers={data.peers} levels={data.maturityLevels} />
      <Actions actions={data.actions} />
    </div>
  );
}

/**
 * "Calculating your results" animation shown while the score/peers fetch is
 * in flight - a spinner plus a rotating checklist of what's being worked out,
 * so the wait reads as the report being assembled rather than a stalled page.
 */
function ResultsLoading() {
  const [step, setStep] = useState(0);

  useEffect(() => {
    const id = setInterval(() => setStep((s) => (s + 1) % LOADING_STEPS.length), MIN_LOADING_MS / LOADING_STEPS.length);
    return () => clearInterval(id);
  }, []);

  return (
    <div className="brs-r-card brs-r-loading" role="status" aria-live="polite">
      <span className="brs-r-loading-spinner" aria-hidden="true" />
      <p className="brs-r-loading-title">Calculating your results…</p>
      <p className="brs-r-loading-step">{LOADING_STEPS[step]}</p>
    </div>
  );
}

// School/shield mark for the header - inline rather than a static asset, so
// it renders crisp at any size without an extra file for WordPress to serve.
function HeaderLogo() {
  return (
    <svg className="brs-r-logo" viewBox="0 0 500 500" role="img" aria-hidden="true">
      <defs>
        <mask id="brs-r-logo-cutout">
          <rect x="0" y="0" width="500" height="500" fill="white" />
          <path d="M 232,355 V 325 A 18,18 0 0,1 268,325 V 355 Z" fill="black" />
          <path
            d="M 243,245 A 7,7 0 0,1 257,245 L 257,272 A 3,3 0 0,1 254,275 H 246 A 3,3 0 0,1 243,272 Z"
            fill="black"
          />
          <rect x="168" y="272" width="14" height="22" rx="2" fill="black" />
          <rect x="188" y="272" width="14" height="22" rx="2" fill="black" />
          <rect x="168" y="308" width="14" height="22" rx="2" fill="black" />
          <rect x="188" y="308" width="14" height="22" rx="2" fill="black" />
          <rect x="298" y="272" width="14" height="22" rx="2" fill="black" />
          <rect x="318" y="272" width="14" height="22" rx="2" fill="black" />
          <rect x="298" y="308" width="14" height="22" rx="2" fill="black" />
          <rect x="318" y="308" width="14" height="22" rx="2" fill="black" />
        </mask>
      </defs>

      <g fill="#ffffff">
        <path
          fillRule="evenodd"
          d="M 250,50
             L 400,110
             L 400,270
             C 400,360 310,425 250,450
             C 190,425 100,360 100,270
             L 100,110
             Z
             M 250,78
             L 122,129
             L 122,268
             C 122,342 198,400 250,422
             C 302,400 378,342 378,268
             L 378,129
             Z"
        />

        <g mask="url(#brs-r-logo-cutout)">
          <rect x="246" y="115" width="8" height="42" />
          <path d="M 254,117 C 270,110 280,125 292,118 L 292,143 C 280,150 270,135 254,142 Z" />
          <polygon points="250,157 205,205 295,205" />
          <polygon points="152,255 210,230 210,240 158,263" />
          <polygon points="348,255 290,230 290,240 342,263" />
          <rect x="210" y="205" width="80" height="150" />
          <rect x="155" y="255" width="55" height="100" />
          <rect x="290" y="255" width="55" height="100" />
          <rect x="150" y="352" width="200" height="6" />
        </g>
      </g>
    </svg>
  );
}

function Header({ data, onBack }) {
  const date = data.submittedAt ? new Date(data.submittedAt.replace(' ', 'T')) : null;
  const dateLabel = date && !isNaN(date) ? date.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }) : '—';

  return (
    <header className="brs-r-header">
      <div className="brs-r-header-left">
        <button type="button" className="brs-r-back-link" onClick={onBack}>
          &larr; Back
        </button>
        <HeaderLogo />
        <div className="brs-r-header-titles">
          <h1>Your Results</h1>
          <p>School Operational Resilience Survey</p>
        </div>
      </div>
      <div className="brs-r-header-meta">
        <div>
          <span className="brs-r-header-label">School Name</span>
          <span className="brs-r-header-value">{data.schoolName || '—'}</span>
        </div>
        <div>
          <span className="brs-r-header-label">Date</span>
          <span className="brs-r-header-value">{dateLabel}</span>
        </div>
        <div>
          <span className="brs-r-header-label">Reference</span>
          <span className="brs-r-header-value">{data.reference}</span>
        </div>
      </div>
    </header>
  );
}

function BestPractice({ score, levels }) {
  const finalLevel = score.band.level;
  const [revealLevel, setRevealLevel] = useState(1);
  const [pointerRect, setPointerRect] = useState(null);
  const segRefs = useRef({});

  // One continuous glide straight from Level 1 to the real score, rather
  // than stopping at each level in between - a chain of separate transitions
  // reads as stepping/pausing, not smooth motion. Duration scales with
  // distance so a one-level hop stays snappy and a full 1->5 sweep still
  // gets enough time to read clearly as movement.
  const glideMs = 260 + (finalLevel - 1) * 90;

  useEffect(() => {
    setRevealLevel(1);

    if (finalLevel <= 1) {
      return undefined;
    }

    // A short tick so Level 1's position paints first, before the single
    // glide to the final level kicks off.
    const id = setTimeout(() => setRevealLevel(finalLevel), 60);
    return () => clearTimeout(id);
  }, [finalLevel]);

  // Measured in pixels (not percentages) so the pointer lines up exactly with
  // its segment's real rendered box, gap included, rather than assuming equal
  // percentage slices.
  useEffect(() => {
    const el = segRefs.current[revealLevel];

    if (el) {
      setPointerRect({ left: el.offsetLeft, width: el.offsetWidth });
    }
  }, [revealLevel, levels]);

  return (
    <section className="brs-r-card">
      <PanelHeading
        n={1}
        title="Rating Against Best Practice"
        body="Your school's operational resilience compared to best practice standards."
      />

      <div className="brs-r-strip-wrap">
        <div className="brs-r-strip">
          {levels.map((level) => (
            <div
              key={level.level}
              ref={(el) => {
                segRefs.current[level.level] = el;
              }}
              className={`brs-r-strip-seg${level.level === revealLevel ? ' brs-r-strip-seg--active' : ''}`}
              style={{ background: LEVEL_COLORS[level.level - 1], color: LEVEL_TEXT[level.level - 1] }}
            >
              <strong>Level {level.level}</strong>
              <span>{level.label}</span>
            </div>
          ))}

          {pointerRect && (
            <div
              className="brs-r-strip-pointer"
              style={{ left: pointerRect.left, width: pointerRect.width, transitionDuration: `${glideMs}ms` }}
            >
              <span className="brs-r-strip-callout">Your Score</span>
            </div>
          )}
        </div>
      </div>
    </section>
  );
}

function Peers({ peers, levels }) {
  // One flag drives the whole reveal sequence - ticks, curve draw, area
  // fade, marker grow, dot pop, labels, then the legend last - each timed
  // via its own CSS transition-delay off this single true/false flip.
  const [revealed, setRevealed] = useState(false);

  useEffect(() => {
    const id = setTimeout(() => setRevealed(true), 50);
    return () => clearTimeout(id);
  }, []);

  return (
    <section className="brs-r-card">
      <PanelHeading
        n={2}
        title="Rating Against Peers"
        body="Your score compared to the distribution of scores across similar schools."
      />

      {peers.sampleSize < 1 ? (
        <p className="brs-r-body">
          You're one of the first schools through the survey — check back once more responses are in to see how you
          compare to peers.
        </p>
      ) : (
        <div className="brs-r-curve-layout">
          <BellCurve peers={peers} levels={levels} revealed={revealed} />
          <div className={`brs-r-curve-legend${revealed ? ' brs-r-curve-legend--revealed' : ''}`}>
            <span className="brs-r-legend-item">
              <i className="brs-r-legend-swatch" style={{ borderColor: PEER_COLOR }} />
              Peer Average
            </span>
            <span className="brs-r-legend-item">
              <i className="brs-r-legend-swatch" style={{ borderColor: YOU_COLOR }} />
              Your Score
            </span>
          </div>
        </div>
      )}
    </section>
  );
}

// Catmull-Rom -> cubic Bezier, so the density curve reads as one continuous
// stroke rather than the straight segments joining each sampled point.
function smoothPath(points) {
  if (points.length < 3) {
    return points.map(([px, py], i) => `${i === 0 ? 'M' : 'L'} ${px} ${py}`).join(' ');
  }

  let d = `M ${points[0][0]} ${points[0][1]}`;

  for (let i = 0; i < points.length - 1; i++) {
    const p0 = points[i === 0 ? 0 : i - 1];
    const p1 = points[i];
    const p2 = points[i + 1];
    const p3 = points[i + 2 < points.length ? i + 2 : i + 1];

    const c1x = p1[0] + (p2[0] - p0[0]) / 6;
    const c1y = p1[1] + (p2[1] - p0[1]) / 6;
    const c2x = p2[0] - (p3[0] - p1[0]) / 6;
    const c2y = p2[1] - (p3[1] - p1[1]) / 6;

    d += ` C ${c1x} ${c1y}, ${c2x} ${c2y}, ${p2[0]} ${p2[1]}`;
  }

  return d;
}

function BellCurve({ peers, levels, revealed }) {
  const width = 640;
  // Bigger bottom margin than the curve itself needs, to stack: the marker
  // dots, their direct "Peer Average" / "Your Score" labels, then the level
  // ticks below that - three rows under the baseline.
  const padding = { top: 10, right: 20, bottom: 70, left: 20 };
  const plotH = 88;
  const height = padding.top + plotH + padding.bottom;
  const plotW = width - padding.left - padding.right;
  const baseline = padding.top + plotH;

  const domainMax = levels.length;
  const mean = peers.meanPosition ?? domainMax / 2;
  // A lone peer (no stdev yet) still gets a visible, plausible curve rather than a spike or flat line.
  const stdev = peers.stdevPosition && peers.stdevPosition > 0.05 ? peers.stdevPosition : 0.6;

  const x = (pos) => padding.left + (pos / domainMax) * plotW;
  const pdf = (pos) => Math.exp(-0.5 * ((pos - mean) / stdev) ** 2);

  const steps = 60;
  const points = Array.from({ length: steps + 1 }, (_, i) => {
    const pos = (i / steps) * domainMax;
    return [x(pos), pdf(pos)];
  });
  const peak = Math.max(...points.map((p) => p[1]), 0.0001);
  const y = (density) => baseline - (density / peak) * plotH;

  const curvePoints = points.map(([px, d]) => [px, y(d)]);
  const linePath = smoothPath(curvePoints);
  const areaPath = `${linePath} L ${x(domainMax)} ${baseline} L ${x(0)} ${baseline} Z`;

  const meanX = x(peers.meanPosition ?? mean);
  const youX = x(peers.myPosition ?? mean);
  const markerLabelY = baseline + 22;
  const tickTitleY = baseline + 42;

  // The line "draws" itself via the classic stroke-dasharray/dashoffset
  // trick - needs the path's real rendered length, which only exists once
  // it's in the DOM, hence the ref + measurement effect.
  const lineRef = useRef(null);
  const [lineLength, setLineLength] = useState(0);

  // Layout effect (runs before paint), not a regular effect - otherwise the
  // browser paints one frame with stroke-dasharray still at its 0 default
  // (a fully solid, undrawn-looking line) before the measured length hides it.
  useLayoutEffect(() => {
    if (lineRef.current) {
      setLineLength(lineRef.current.getTotalLength());
    }
  }, [linePath]);

  return (
    <div className={`brs-r-curve-wrap${revealed ? ' brs-r-curve-wrap--revealed' : ''}`}>
      <svg viewBox={`0 0 ${width} ${height}`} className="brs-r-curve" role="img" aria-label="Distribution of peer scores with your score marked">
        <path d={areaPath} className="brs-r-curve-area" />
        <path
          ref={lineRef}
          d={linePath}
          className="brs-r-curve-line"
          style={{
            strokeDasharray: lineLength,
            strokeDashoffset: revealed ? 0 : lineLength,
          }}
        />

        <line
          x1={meanX}
          x2={meanX}
          y1={padding.top}
          y2={baseline}
          className="brs-r-marker"
          style={{ stroke: PEER_COLOR, transformOrigin: `${meanX}px ${baseline}px` }}
        />
        <line
          x1={youX}
          x2={youX}
          y1={padding.top}
          y2={baseline}
          className="brs-r-marker"
          style={{ stroke: YOU_COLOR, transformOrigin: `${youX}px ${baseline}px` }}
        />

        <circle
          cx={meanX}
          cy={baseline}
          r="5"
          className="brs-r-marker-dot"
          style={{ fill: PEER_COLOR, transformOrigin: `${meanX}px ${baseline}px` }}
        />
        <circle
          cx={youX}
          cy={baseline}
          r="5"
          className="brs-r-marker-dot"
          style={{ fill: YOU_COLOR, transformOrigin: `${youX}px ${baseline}px` }}
        />

        <text x={meanX} y={markerLabelY} textAnchor="middle" className="brs-r-marker-label" style={{ fill: PEER_COLOR }}>
          Peer Average
        </text>
        <text x={youX} y={markerLabelY} textAnchor="middle" className="brs-r-marker-label" style={{ fill: YOU_COLOR }}>
          Your Score
        </text>

        {levels.map((level) => (
          <text key={level.level} x={x(level.level - 0.5)} y={tickTitleY} textAnchor="middle" className="brs-r-curve-tick">
            <tspan x={x(level.level - 0.5)} className="brs-r-curve-tick-title">{`Level ${level.level}`}</tspan>
            <tspan x={x(level.level - 0.5)} dy="1.2em" className="brs-r-curve-tick-sub">{level.label}</tspan>
          </text>
        ))}
      </svg>
    </div>
  );
}

// Groups keep the items' existing gap-descending order, just split apart -
// so within a section the worst gaps still come first, and the section with
// the single worst item leads (Map preserves insertion/first-seen order).
function groupBySection(actions) {
  const groups = new Map();

  for (const action of actions) {
    const key = action.section || 'General';

    if (!groups.has(key)) {
      groups.set(key, []);
    }

    groups.get(key).push(action);
  }

  return Array.from(groups.entries());
}

function Actions({ actions }) {
  return (
    <section className="brs-r-card">
      <PanelHeading
        n={3}
        title="Recommended Actions"
        body="Focus on these key actions to strengthen your operational resilience."
      />

      {actions.length === 0 ? (
        <p className="brs-r-body">No specific gaps identified — nice work.</p>
      ) : (
        groupBySection(actions).map(([section, items]) => (
          <div className="brs-r-action-group" key={section}>
            <h3 className="brs-r-action-group-title">{section}</h3>
            <ol className="brs-r-actions">
              {items.map((action, i) => (
                <li key={action.id} className="brs-r-action">
                  <span className="brs-r-action-number">{i + 1}.</span>
                  <span>
                    <strong>{action.label}: </strong>
                    {action.feedback}
                  </span>
                </li>
              ))}
            </ol>
          </div>
        ))
      )}
    </section>
  );
}

function PanelHeading({ n, title, body }) {
  return (
    <div className="brs-r-panel-heading">
      <span className="brs-r-panel-number">{n}</span>
      <div>
        <h2>{title}</h2>
        <p>{body}</p>
      </div>
    </div>
  );
}
