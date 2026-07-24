# Bounce Resilience Survey

WordPress plugin. React form on the front end, custom table for storage, CSV export from the admin.

## Local setup

```bash
npm install
npm run dev     # React only, with mock data — no WordPress needed
npm run build   # outputs build/survey.js and build/survey.css
```

Work in `npm run dev` while building the UI. Only run `npm run build` when you need to test the
real submission path against WordPress.

`index.html` in the project root is the dev shell only — Vite serves it at localhost:5173 so the
form renders without WordPress. It is not part of the production build and does not need to be
uploaded. `npm run build` compiles `src/main.jsx` directly to `build/survey.js`.

## Installing into WordPress

1. Zip the whole `bounce-resilience-survey` folder (including `build/`).
2. Plugins → Add New → Upload Plugin → activate. The table is created on activation.
3. Create a page, assign a blank/canvas template, add the shortcode `[bounce_survey]`.

## Admin

- **Bounce Survey → Submissions** — paginated list, plus Download CSV.
- **Bounce Survey → Question wording** — non-technical staff can edit question text, help text,
  answer/scale labels and section titles at any time, including after responses start arriving.
  There is deliberately no way to add, remove or reorder questions, sections or options from this
  screen — see "Data model" below for why.

## Saving submissions to OneDrive (manual, for now)

There's no automated sync yet — that needs either reliable outgoing email from VentraIP or a
Microsoft Graph API integration, both bigger jobs than "make the form live". For now:

1. **Bounce Survey → Submissions → Download CSV.**
2. Save the file directly into whichever folder on your PC OneDrive is already syncing (e.g.
   `OneDrive/Bounce Survey/`). OneDrive picks it up automatically — no extra step.
3. Do this on whatever cadence you need (e.g. weekly). The CSV always contains every submission
   to date, so you can just overwrite the previous file each time.

If this becomes a chore, the next step up is a scheduled task that emails the CSV to you
automatically; the step beyond that is pushing straight into OneDrive via Microsoft Graph. Both
are real additional builds, not configuration — ask when you're ready for either.

## Survey definition

`data/questions.json` holds all 58 questions across 11 sections, transcribed from the Microsoft
Form. PHP reads it at runtime; the dev server fetches the same file. Edit it there — it is the
single source of truth for both sides.

Types in use: `single`, `multiple` (with optional `maxSelect`), `likert` (matrix), `rating`
(stars), `text`, `textarea`. A question may carry `visibleIf` for conditional display.

## Data model

Answers are stored as JSON keyed by question id, never by question text. Shape varies by type:

| Type | Stored as |
| --- | --- |
| single | `"Independent"` |
| multiple | `["Email","SMS / mass notification platform"]` |
| likert | `{"0":"Annual","1":"Quarterly"}` — row index to column label |
| rating | `4` |
| text / textarea | `"free text"` |

**Never change or reuse a question id once collection has started.** The same goes for the number
of options on a choice question, or the number of rows/columns on a likert question — that's why
those are fixed in `data/questions.json` and only the *labels* are editable from
Bounce Survey → Question wording.

Phase 1 stores raw answers only. `total_score` stays null until the maturity model arrives.

The email address question at the very start of the form (wording in `BRS_Config::copy()` /
`src/main.jsx`) is a phase-1-only addition, requested so early respondents aren't locked out of
getting a personalised report later. Bounce's plan is to remove it once the data visualisation
work is done and rely on the reference number instead — flag this when that phase starts.

Each submission gets a reference like `KQ7MP-4XNRB`, shown to the respondent and used by the
phase 2 results lookup.

## Endpoints

- `POST /wp-json/bounce/v1/submit` — public. Validates every answer against the permitted score
  values, honeypot field, 5 submissions per IP per hour.
- `GET /wp-json/bounce/v1/result/{reference}` — stub for phase 2. Returns the stored score.
  Does not expose the email address.

## Before this goes live

- [ ] Verify `data/questions.json` against the live Microsoft Form, particularly Q1 (only two
      options were present in the source) and the branching on Q7/Q8/Q9 and Q44/Q45/Q46.
- [ ] Set the brand tokens at the top of `src/survey.css` to the Bounce values.
- [ ] Confirm the PHP version on VentraIP matches what you developed against.
- [ ] Exclude the survey page from any caching plugin.
- [ ] Confirm the page slug and whether it should be `noindex`.

## Not built yet (phase 2, separate estimate)

- Scoring bands, maturity levels and the personalised results view — blocked on the scoring model
  from Bounce.
- Retroactive results for early respondents (lookup + email send).
- Microsoft Forms import into `brs_submissions` (`source = 'msforms'`, `external_id` = response id).
- Automated OneDrive sync. For now, CSV export is manual.
