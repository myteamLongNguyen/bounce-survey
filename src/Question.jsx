export const OTHER = 'Other';

/**
 * Renders one question. Five types appear in this form:
 * single, multiple, likert, rating, text/textarea.
 */
export default function Question({ question: q, value, otherText, onOtherChange, onChange, missing }) {
  return (
    <fieldset className={`brs-question${missing ? ' brs-question--missing' : ''}`}>
      <legend className="brs-legend">
        <span className="brs-number">{q.number}</span>
        <span className="brs-question-text">
          {q.text}
          {!q.required && <span className="brs-optional"> (optional)</span>}
        </span>
      </legend>

      {q.subtext && <p className="brs-subtext">{q.subtext}</p>}

      {q.type === 'single' && (
        <SingleChoice
          q={q}
          value={value}
          onChange={onChange}
          otherText={otherText}
          onOtherChange={onOtherChange}
        />
      )}
      {q.type === 'multiple' && (
        <MultipleChoice
          q={q}
          value={value}
          onChange={onChange}
          otherText={otherText}
          onOtherChange={onOtherChange}
        />
      )}
      {q.type === 'likert' && <Likert q={q} value={value} onChange={onChange} />}
      {q.type === 'rating' && <Rating q={q} value={value} onChange={onChange} />}
      {(q.type === 'text' || q.type === 'textarea') && (
        <FreeText q={q} value={value} onChange={onChange} />
      )}

      {missing && <p className="brs-inline-error">Please answer this question.</p>}
    </fieldset>
  );
}

function SingleChoice({ q, value, onChange, otherText, onOtherChange }) {
  return (
    <div className="brs-options">
      {q.options.map((opt) => (
        <label key={opt} className="brs-option">
          <input
            type="radio"
            name={q.id}
            checked={value === opt}
            onChange={() => onChange(opt)}
          />
          <span>{opt}</span>
        </label>
      ))}

      {q.allowOther && (
        <OtherChoice
          type="radio"
          name={q.id}
          checked={value === OTHER}
          onSelect={() => onChange(OTHER)}
          text={otherText}
          onText={onOtherChange}
        />
      )}
    </div>
  );
}

function MultipleChoice({ q, value, onChange, otherText, onOtherChange }) {
  const picked = Array.isArray(value) ? value : [];
  const atLimit = q.maxSelect && picked.length >= q.maxSelect;

  function toggle(opt) {
    if (picked.includes(opt)) {
      onChange(picked.filter((p) => p !== opt));
    } else if (!atLimit) {
      onChange([...picked, opt]);
    }
  }

  return (
    <>
      {q.maxSelect && (
        <p className="brs-hint">
          Select up to {q.maxSelect} ({picked.length} chosen)
        </p>
      )}
      <div className="brs-options">
        {q.options.map((opt) => {
          const on = picked.includes(opt);
          return (
            <label
              key={opt}
              className={`brs-option${!on && atLimit ? ' brs-option--disabled' : ''}`}
            >
              <input
                type="checkbox"
                checked={on}
                disabled={!on && atLimit}
                onChange={() => toggle(opt)}
              />
              <span>{opt}</span>
            </label>
          );
        })}

        {q.allowOther && (
          <OtherChoice
            type="checkbox"
            name={q.id}
            checked={picked.includes(OTHER)}
            disabled={!picked.includes(OTHER) && atLimit}
            onSelect={() => toggle(OTHER)}
            text={otherText}
            onText={onOtherChange}
          />
        )}
      </div>
    </>
  );
}

/**
 * "Other" behaves like any option but reveals a free-text box when chosen.
 * The typed value is stored separately, under `<question id>_other`.
 */
function OtherChoice({ type, name, checked, disabled, onSelect, text, onText }) {
  return (
    <div className="brs-other">
      <label className={`brs-option${disabled ? ' brs-option--disabled' : ''}`}>
        <input type={type} name={name} checked={checked} disabled={disabled} onChange={onSelect} />
        <span>{OTHER}</span>
      </label>

      {checked && (
        <input
          type="text"
          className="brs-input brs-other-input"
          value={text || ''}
          onChange={(e) => onText(e.target.value)}
          placeholder="Please specify"
          aria-label="Please specify"
        />
      )}
    </div>
  );
}

/**
 * Matrix on wider screens, stacked cards on narrow ones — a 15-row by 6-column
 * grid is unusable on a phone otherwise.
 */
function Likert({ q, value, onChange }) {
  const grid = value || {};

  function pick(rowIndex, column) {
    onChange({ ...grid, [rowIndex]: column });
  }

  return (
    <>
      <div className="brs-likert-scroll">
        <table className="brs-likert">
          <thead>
            <tr>
              <th />
              {q.columns.map((c) => (
                <th key={c} scope="col">
                  {c}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {q.rows.map((row, i) => (
              <tr key={row} className={grid[i] === undefined ? 'brs-likert-row--empty' : ''}>
                <th scope="row">{row}</th>
                {q.columns.map((c) => (
                  <td key={c}>
                    <label className="brs-likert-cell">
                      <input
                        type="radio"
                        name={`${q.id}-${i}`}
                        checked={grid[i] === c}
                        onChange={() => pick(i, c)}
                      />
                      <span className="brs-sr">{`${row}: ${c}`}</span>
                    </label>
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="brs-likert-cards">
        {q.rows.map((row, i) => (
          <div key={row} className="brs-likert-card">
            <p className="brs-likert-card-label">{row}</p>
            <div className="brs-options">
              {q.columns.map((c) => (
                <label key={c} className="brs-option">
                  <input
                    type="radio"
                    name={`${q.id}-m-${i}`}
                    checked={grid[i] === c}
                    onChange={() => pick(i, c)}
                  />
                  <span>{c}</span>
                </label>
              ))}
            </div>
          </div>
        ))}
      </div>
    </>
  );
}

function Rating({ q, value, onChange }) {
  const stars = q.stars || 5;
  const current = Number(value) || 0;

  return (
    <div className="brs-rating">
      <span className="brs-rating-label">{q.lowLabel}</span>
      <div className="brs-stars" role="radiogroup" aria-label={q.text}>
        {Array.from({ length: stars }, (_, i) => i + 1).map((n) => (
          <button
            key={n}
            type="button"
            role="radio"
            aria-checked={current === n}
            aria-label={`${n} of ${stars}`}
            className={`brs-star${n <= current ? ' brs-star--on' : ''}`}
            onClick={() => onChange(n)}
          >
            ★
          </button>
        ))}
      </div>
      <span className="brs-rating-label">{q.highLabel}</span>
    </div>
  );
}

function FreeText({ q, value, onChange }) {
  if (q.type === 'text') {
    return (
      <input
        type="text"
        className="brs-input"
        value={value || ''}
        onChange={(e) => onChange(e.target.value)}
      />
    );
  }

  return (
    <textarea
      className="brs-input"
      rows={4}
      value={value || ''}
      onChange={(e) => onChange(e.target.value)}
      placeholder="Enter your answer"
    />
  );
}