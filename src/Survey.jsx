import { useState, useRef } from 'react';
import Question, { OTHER } from './Question.jsx';
import Results, { ReferenceLookupForm } from './Results.jsx';
import './results.css';

export default function Survey() {
  // Read at render time, not at module scope. ES module imports are evaluated
  // before the body of the module that imports them, so a top-level read here
  // would run before main.jsx has a chance to set up the dev mock.
  const { restUrl, resultsUrlBase, nonce, survey, copy, banner, logo } = window.BRS_DATA;
  const sections = survey.sections;

  // A reference in the URL (shared link, or a bookmark from a previous visit)
  // opens straight into the results view - same page, no separate page to route to.
  const [resultsRef, setResultsRef] = useState(
    () => new URLSearchParams(window.location.search).get('ref') || null
  );

  const [step, setStep] = useState(0);
  const [answers, setAnswers] = useState({});
  const [honeypot, setHoneypot] = useState('');
  const [status, setStatus] = useState('idle'); // idle | submitting | error
  const [error, setError] = useState(null);
  const [showMissing, setShowMissing] = useState(false);

  const topRef = useRef(null);
  const isLast = step === sections.length - 1;
  const section = sections[step];

  if (resultsRef) {
    return <Results reference={resultsRef} restUrlBase={resultsUrlBase} onBack={() => setResultsRef(null)} logo={logo} />;
  }

  function setAnswer(id, value) {
    setAnswers((prev) => ({ ...prev, [id]: value }));
  }

  // A question with a visibleIf clause only applies when its controlling
  // answer matches. Without this, a New Zealand school could never submit,
  // because the Australian state question is marked required in the source form.
  function isVisible(q) {
    if (!q.visibleIf) return true;
    const controller = answers[q.visibleIf.question];
    if (q.visibleIf.equals !== undefined) return controller === q.visibleIf.equals;
    if (q.visibleIf.in) return q.visibleIf.in.includes(controller);
    if (q.visibleIf.notIn) {
      const selected = Array.isArray(controller) ? controller : [controller];
      return !q.visibleIf.notIn.some((excluded) => selected.includes(excluded));
    }
    return true;
  }

  function isAnswered(q) {
    const v = answers[q.id];
    if (v === undefined || v === null || v === '') return false;

    // Choosing "Other" without typing anything is not an answer.
    if (q.allowOther) {
      const chose = Array.isArray(v) ? v.includes(OTHER) : v === OTHER;
      if (chose && !String(answers[`${q.id}_other`] || '').trim()) return false;
    }

    if (Array.isArray(v)) return v.length > 0;
    if (q.type === 'likert') return Object.keys(v).length === q.rows.length;
    return true;
  }

  const visibleHere = section.questions.filter(isVisible);
  const missingHere = visibleHere.filter((q) => q.required && !isAnswered(q));

  function scrollTop() {
    topRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function goNext() {
    if (missingHere.length) {
      setShowMissing(true);
      setError(copy.errorIncomplete);
      return;
    }
    setShowMissing(false);
    setError(null);
    setStep((s) => s + 1);
    scrollTop();
  }

  function goBack() {
    setShowMissing(false);
    setError(null);
    setStep((s) => Math.max(0, s - 1));
    scrollTop();
  }

  async function submit() {
    if (missingHere.length) {
      setShowMissing(true);
      setError(copy.errorIncomplete);
      return;
    }

    setStatus('submitting');
    setError(null);

    try {
      const res = await fetch(restUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        body: JSON.stringify({ answers, website: honeypot }),
      });

      const data = await res.json().catch(() => null);

      if (!res.ok) {
        setError(data?.message || copy.errorGeneric);
        setStatus('error');
        return;
      }

      // Straight into the results view, in place, on this same page - no
      // confirmation screen, no navigation.
      setResultsRef(data.reference);
    } catch {
      setError(copy.errorNetwork);
      setStatus('error');
    }
  }

  const pct = Math.round(((step + 1) / sections.length) * 100);

  return (
    <div className="brs-panel" ref={topRef}>
      {step === 0 ? (
        <>
          <div className="brs-hero">
            {banner && (
              <div className="brs-banner-frame">
                <img className="brs-banner" src={banner} alt="" />
              </div>
            )}

            <h1 className="brs-heading brs-heading--hero">{survey.title}</h1>
            {survey.subtitle && <p className="brs-body brs-body--hero">{survey.subtitle}</p>}

            {survey.intro && (
              <div className="brs-intro">
                {survey.intro.map((block, i) => (
                  <div className="brs-intro-block" key={i}>
                    <p className="brs-intro-title">{block.title}</p>
                    <p
                      className={`brs-body brs-body--hero${
                        block.title === 'Privacy' ? ' brs-body--italic' : ''
                      }`}
                    >
                      {block.body}
                    </p>
                  </div>
                ))}
              </div>
            )}
          </div>

          <div className="brs-lookup">
            <p className="brs-body brs-body--muted">Already submitted? Enter your reference to view your results.</p>
            <ReferenceLookupForm onSubmit={setResultsRef} />
          </div>

          {survey.formNote && <p className="brs-form-note">{survey.formNote}</p>}
        </>
      ) : (
        <header className="brs-header">
          <h1 className="brs-heading">{survey.title}</h1>
        </header>
      )}

      <div className="brs-progress">
        <div className="brs-progress-meta">
          <span>
            Section {section.number} of {sections.length}
          </span>
          <span>{pct}%</span>
        </div>
        <div className="brs-progress-track">
          <div className="brs-progress-fill" style={{ width: `${pct}%` }} />
        </div>
      </div>

      {/* Honeypot. Hidden from people, tempting to bots. */}
      <div className="brs-hp" aria-hidden="true">
        <label htmlFor="brs-website">Website</label>
        <input
          id="brs-website"
          type="text"
          name="website"
          tabIndex={-1}
          autoComplete="off"
          value={honeypot}
          onChange={(e) => setHoneypot(e.target.value)}
        />
      </div>

      <h2 className="brs-section-title">{section.title}</h2>

      {visibleHere.map((q) => (
        <Question
          key={q.id}
          question={q}
          value={answers[q.id]}
          otherText={answers[`${q.id}_other`]}
          onOtherChange={(v) => setAnswer(`${q.id}_other`, v)}
          onChange={(v) => setAnswer(q.id, v)}
          missing={showMissing && q.required && !isAnswered(q)}
        />
      ))}

      {error && (
        <p className="brs-error" role="alert">
          {error}
        </p>
      )}

      <div className="brs-nav">
        {step > 0 && (
          <button type="button" className="brs-btn brs-btn--ghost" onClick={goBack}>
            {copy.backLabel}
          </button>
        )}

        {isLast ? (
          <button
            type="button"
            className="brs-btn"
            onClick={submit}
            disabled={status === 'submitting'}
          >
            {status === 'submitting' ? copy.submittingText : copy.submitLabel}
          </button>
        ) : (
          <button type="button" className="brs-btn" onClick={goNext}>
            {copy.nextLabel}
          </button>
        )}
      </div>
    </div>
  );
}