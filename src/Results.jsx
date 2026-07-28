import { useEffect, useState } from 'react';

// Ordinal risk strip: red -> orange -> yellow -> green -> green. A 5-step
// same-direction ramp packed this tightly cannot clear full CVD separation on
// its own (checked against the project's palette validator) - every segment
// therefore always carries its own visible text label (never color alone),
// which is the documented mitigation for that failure mode.
const LEVEL_COLORS = ['#d03b3b', '#ec835a', '#eda100', '#8bb82f', '#0ca30c'];
const LEVEL_TEXT = ['#ffffff', '#ffffff', '#3a2e00', '#183300', '#ffffff'];

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

    fetch(url)
      .then(async (res) => {
        const body = await res.json().catch(() => null);
        if (!res.ok) throw new Error(body?.message || 'We could not find results for that reference.');
        return body;
      })
      .then((data) => setState({ status: 'loaded', data, error: null }))
      .catch((err) => setState({ status: 'error', data: null, error: err.message }));
  }, [reference, restUrlBase]);

  if (state.status === 'error') {
    return (
      <div className="brs-r-card brs-r-lookup">
        <p className="brs-r-error">{state.error}</p>
        <button type="button" className="brs-r-btn brs-r-btn--ghost" onClick={onBack}>
          Back
        </button>
      </div>
    );
  }

  if (state.status === 'loading') {
    return (
      <div className="brs-r-card">
        <p className="brs-r-body">Loading your results…</p>
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

function Header({ data, onBack }) {
  const date = data.submittedAt ? new Date(data.submittedAt.replace(' ', 'T')) : null;
  const dateLabel = date && !isNaN(date) ? date.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }) : '—';

  return (
    <header className="brs-r-header">
      <div className="brs-r-header-title">
        <h1>Your Results</h1>
        <p>School Operational Resilience Survey</p>
        <button type="button" className="brs-r-back-link" onClick={onBack}>
          &larr; Back
        </button>
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
      </div>
    </header>
  );
}

function BestPractice({ score, levels }) {
  return (
    <section className="brs-r-card">
      <PanelHeading
        n={1}
        title="Rating Against Best Practice"
        body="Your school's operational resilience compared to best practice standards."
      />

      <div className="brs-r-strip-wrap">
        <div className="brs-r-strip">
          {levels.map((level) => {
            const active = level.level === score.band.level;
            return (
              <div
                key={level.level}
                className={`brs-r-strip-seg${active ? ' brs-r-strip-seg--active' : ''}`}
                style={{ background: LEVEL_COLORS[level.level - 1], color: LEVEL_TEXT[level.level - 1] }}
              >
                {active && <span className="brs-r-strip-callout">Your Score</span>}
                <strong>Level {level.level}</strong>
                <span>{level.label}</span>
              </div>
            );
          })}
        </div>
      </div>
    </section>
  );
}

function Peers({ peers, levels }) {
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
        <BellCurve peers={peers} levels={levels} />
      )}
    </section>
  );
}

function BellCurve({ peers, levels }) {
  const width = 640;
  const height = 220;
  const padding = { top: 10, right: 20, bottom: 34, left: 20 };
  const plotW = width - padding.left - padding.right;
  const plotH = height - padding.top - padding.bottom;

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
  const y = (density) => padding.top + plotH - (density / peak) * plotH;

  const linePath = points.map(([px, d], i) => `${i === 0 ? 'M' : 'L'} ${px} ${y(d)}`).join(' ');
  const areaPath = `${linePath} L ${x(domainMax)} ${padding.top + plotH} L ${x(0)} ${padding.top + plotH} Z`;

  const meanX = x(peers.meanPosition ?? mean);
  const youX = x(peers.myPosition ?? mean);

  return (
    <div className="brs-r-curve-wrap">
      <svg viewBox={`0 0 ${width} ${height}`} className="brs-r-curve" role="img" aria-label="Distribution of peer scores with your score marked">
        <path d={areaPath} className="brs-r-curve-area" />
        <path d={linePath} className="brs-r-curve-line" />

        <line x1={meanX} x2={meanX} y1={padding.top} y2={padding.top + plotH} className="brs-r-marker" style={{ stroke: PEER_COLOR }} />
        <line x1={youX} x2={youX} y1={padding.top} y2={padding.top + plotH} className="brs-r-marker" style={{ stroke: YOU_COLOR }} />

        {levels.map((level) => (
          <text key={level.level} x={x(level.level - 0.5)} y={height - 10} textAnchor="middle" className="brs-r-curve-tick">
            {`Level ${level.level}`}
          </text>
        ))}
      </svg>

      <div className="brs-r-curve-legend">
        <span className="brs-r-legend-item">
          <i style={{ background: PEER_COLOR }} /> Peer Average
        </span>
        <span className="brs-r-legend-item">
          <i style={{ background: YOU_COLOR }} /> Your Score
        </span>
        <span className="brs-r-legend-note">Based on {peers.sampleSize} other {peers.sampleSize === 1 ? 'response' : 'responses'}</span>
      </div>
    </div>
  );
}

function Actions({ actions }) {
  return (
    <section className="brs-r-card">
      <PanelHeading
        n={3}
        title="Recommended Actions"
        body="Focus on these key actions to strengthen your operational resilience."
      />

      <ul className="brs-r-actions">
        {actions.length === 0 && <li className="brs-r-body">No specific gaps identified — nice work.</li>}
        {actions.map((action) => (
          <li key={action.id} className="brs-r-action">
            <span className="brs-r-action-check" aria-hidden="true" />
            <span>
              <strong>{action.label}: </strong>
              {action.feedback}
            </span>
          </li>
        ))}
      </ul>
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
