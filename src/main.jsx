import { createRoot } from "react-dom/client";
import Survey from "./Survey.jsx";
import "./survey.css";

// In `npm run dev` there is no WordPress, so stand in for what
// wp_localize_script would normally provide. Vite strips this from
// production builds, and the real questions.json is read by PHP.
async function boot() {
  if (import.meta.env.DEV && !window.BRS_DATA) {
    // fetch rather than import(): a dynamic import forces import.meta into the
    // IIFE bundle, which rolldown warns about. Vite serves the project root in
    // dev, so this path resolves without any extra config.
    const survey = await fetch("/data/questions.json").then((r) => r.json());

    window.BRS_DATA = {
      restUrl: "/mock-submit",
      resultsUrlBase: "/mock-results/",
      nonce: "dev",
      survey,
      copy: COPY,
      banner: "/assets/banner.png",
      logo: "/assets/BOUNCECIRCLE_RGB_white.png",
    };
  }

  const el = document.getElementById("brs-root");

  if (el) {
    createRoot(el).render(<Survey />);
  }
}

const COPY = {
  nextLabel: "Next",
  backLabel: "Back",
  submitLabel: "Submit responses",
  submittingText: "Submitting…",
  successTitle: "Thank you — your responses have been recorded.",
  successBody:
    "Your reference number is below. Keep it if you would like to retrieve your results later.",
  errorGeneric:
    "Something went wrong and your responses were not saved. Please try again.",
  errorNetwork:
    "Could not reach the server. Check your connection and try again.",
  errorIncomplete:
    "Please answer every required question in this section before continuing.",
};

boot();
