# USERS.md — Clinical Co-Pilot

## Target User

**Family physician**, small-to-mid practice, 20 patients per day in 15-minute slots. Uses OpenEMR as their primary EHR.

---

## Agent scope

**Informative only.** The agent summarizes and surfaces facts from the record (and, where applicable, schedule or task context). It does **not** modify anything in OpenEMR — no writes to the chart, orders, problem or medication lists, or patient messages, and no automated sending of communications.

**No recommendations.** The agent does **not** advise what to prescribe, order, refer, or document, nor whom to call or what to do next clinically or operationally. It presents what is already on file (and plain-language *drafts* only where a use case explicitly allows them, for the physician to edit). Individual use cases spell out additional limits; this section is the umbrella rule.

---

## Workflow

### Start of day (physician)
Before the first patient or during the first quiet minutes, the physician looks at **today's schedule** to remember who is on the column, who is new to the practice, and whether anything on file stands out for specific slots. They want a **quick read** across the whole day — not a deep briefing per patient (that comes when they open each encounter). **Use Case 1** (early-morning day summary) supports this pass.

### Pre-visit intake (clinician)
Before the physician enters the room, a clinician visits the patient and handles intake:
- Records vitals (blood pressure, weight, temperature, pulse)
- Updates the medication list for any changes since the last visit
- Documents the chief complaint and reason for today's visit
- Notes any new symptoms or concerns the patient raises

This intake is documented directly into OpenEMR and is available to the physician immediately.

### Between patients (physician)
The physician finishes documenting the previous visit and has approximately 90 seconds before walking into the next room. They open the patient encounter in OpenEMR.

**This is when the per-patient briefing (Use Case 2) runs.**

The agent's job here is to synthesize two things:
- **What the clinician just captured** — today's vitals, chief complaint, medication updates
- **Everything in the patient's history** — prior labs, past visit notes, active diagnoses, current medications, open care gaps

The physician needs this synthesis in a single paragraph they can read in 20 seconds. They do not have time to read three encounter notes, cross-reference labs against the medication list, and check for open referrals separately.

### In the room (physician)
The physician is with the patient. A question comes up that requires pulling something specific from the chart — a lab trend, a medication dose, a referral status. They need the answer in under 10 seconds without breaking the conversation. **Use Case 4** (in-room follow-up question) covers these lookups.

### After the visit — patient message (physician)
After the visit is documented, the physician often sends the patient a brief recap: what was discussed, any new orders or referrals, and how to follow up. That message should match the **official record** of the encounter. The physician still edits and approves every word before it goes out. **Use Case 5** (post-appointment patient message) can draft that recap from the chart.

### Post-lunch (physician)
After lunch, the physician re-orients to **the rest of the day**: who is still on the column, which intakes have been completed since the morning scan, and whether anything new landed on the chart during the break. They want the same **quick, schedule-wide read** as in the morning, but scoped to **remaining** appointments (and any same-day add-ons that now appear on the schedule). **Use Case 6** (post-lunch schedule summary) supports this pass.

### End of day (physician)
The physician has seen most of their patients. One or two may have missed appointments. They want to know if there is anything time-sensitive in those charts before tomorrow. **Use Case 7** (no-show sweep) supports that review.

---

## Use Cases

---

### Use Case 1 — Early-morning day summary

**Trigger:** Physician opens **today's schedule** (or the next session's schedule) at the start of the clinical day, or on demand when they want a fresh pass across the whole column.

**What the agent delivers** (wide and shallow — one or two **factual** lines per scheduled slot, drawn from the schedule row and each chart as linked in OpenEMR):
- Time and patient identifier as shown on the schedule; **new vs established** when that status is on file
- Reason for visit, chief complaint, or visit type when documented (scheduling template, intake, or recent chart — whatever is reliably on file)
- **Chart facts only** that help orientation without duplicating the full pre-visit briefing: examples include final or new critical results already on file, obvious **Use Case 3–style** items if the product surfaces them in scan mode, referral or imaging **pending vs resulted** when statuses exist, or a plain **"no same-day intake yet"** when intake is missing and that state is detectable

Slots with nothing notable beyond identity and time get a minimal line so the screen stays scannable. Per-patient depth stays in **Use Case 2** when the physician opens the encounter.

**Latency requirement:** Summary for a full typical day (~20 slots) in under 20 seconds.

**What the agent must not do:** Suggest visit order, staffing moves, or "who to worry about first." Do not add management advice, new tasks in the EHR, or chart edits. Surface only what the schedule and record already show; the physician runs the day.

---

### Use Case 2 — Pre-visit briefing

**Trigger:** Physician opens a patient encounter in OpenEMR.

**What the agent delivers** (within 5 seconds of encounter open):
- Chief complaint for today's visit (from clinician intake)
- Vitals from today's intake with any significant changes from last visit flagged
- Active problem list (top 3–5 conditions)
- Current medications — changes since last visit highlighted
- Most recent labs with abnormal values flagged
- Last visit summary in 2 sentences
- Open care gaps (overdue screenings, unresolved referrals)

The briefing is organized around today's chief complaint — not a generic chart dump. If the patient is here for a BP recheck, the agent leads with BP history, current antihypertensives, and any relevant labs. Everything else is secondary.

**Latency requirement:** Full briefing in under 5 seconds from encounter open.

**What the agent must not do:** Generate clinical recommendations. Surface the data and the connections; do not say "consider adjusting the dose."

---

### Use Case 3 — Critical flag surfacing

**Trigger:** Automatically included in the pre-visit briefing (**Use Case 2**), or surfaced on-demand.

**What the agent flags proactively:**
- Potential drug-drug or drug-condition interactions in the current medication list
- Abnormal lab values not addressed in the last visit note
- Overdue preventive care (Pap smear, colonoscopy, flu vaccine)
- Referrals ordered with no result on file after 60+ days

Flags include context — not just "drug interaction detected" but which medications, what the relevant condition is, and why the combination matters for this patient.

**Latency requirement:** Flags are part of the pre-visit briefing (5-second window). On-demand flag queries follow the in-room Q&A window (8 seconds; **Use Case 4**).

**What the agent must not do:** Tell the physician what to do about the flag.

---

### Use Case 4 — In-room follow-up question

**Trigger:** Physician asks a pointed question during an active encounter.

**Examples:**
- "What has his A1C been over the last 18 months?"
- "Is she on anything that interacts with metronidazole?"
- "When was her last mammogram?"
- "What dose of metformin is he currently on?"
- "Did we ever get the cardiology referral result back?"

The agent returns a direct answer grounded in the patient's record. The physician reads it and continues the conversation with the patient.

**Latency requirement:** Answer in under 8 seconds.

**What the agent must not do:** Interpret results. Return the values and let the physician interpret.

---

### Use Case 5 — Post-appointment patient message

**Trigger:** The physician indicates they want a patient-facing message for **this completed or nearly completed visit** (for example after the note and orders reflect what they intend to communicate).

**What the agent delivers:**
- A **draft message in plain language** the patient can read on their phone or portal — short paragraphs or bullets, warm and clear, minimal jargon
- Content **grounded only in what is documented** in today's encounter and associated structured data for this visit (visit note, assessment/plan, orders and referrals placed today, return instructions already written in the chart). The draft reflects the appointment as recorded; it does not introduce a separate "clinical opinion."
- Optional **second block:** a one-line "chart recap" for the physician only — ultra-tight summary of what the draft was based on — so they can confirm the message matches their intent before sending

The physician **reviews, edits, and sends** the message through their normal workflow. The agent does not contact the patient directly.

**Latency requirement:** Draft message ready in under 10 seconds after the physician requests it.

**What the agent must not do:** Add instructions, diagnoses, medication changes, or follow-up steps that are **not** present in the encounter documentation. Do not minimize red-flag symptoms or promise outcomes. Do not send the message or choose the delivery channel — the physician does. If the note is empty or contradictory, the agent says so briefly instead of inventing visit content.

---

### Use Case 6 — Post-lunch schedule summary

**Trigger:** Physician returns from lunch and opens **today's schedule** in post-lunch mode, or on demand when they want a fresh pass over **the rest of the column** (first afternoon slot through end of session — exact window follows product configuration, e.g. a fixed time or "remaining only").

**What the agent delivers** (same **wide and shallow** pattern as **Use Case 1** — one or two **factual** lines per included slot):
- **Remaining** scheduled patients (and add-ons on the schedule if present): time, identifier as on the schedule, new vs established when on file, reason for visit / chief complaint / visit type when documented
- **Chart facts only** for orientation: e.g. intake present or still missing when detectable, new resulted labs or imaging on file since the morning, **Use Case 3–style** scan hints if the product includes them here, pending vs resulted referral or order statuses when on file
- Optionally a **single factual session line** when the data exists without inference (for example how many morning encounters are already signed or how many afternoon slots remain — counts from the schedule/EHR only)

Morning patients already seen are **not** re-summarized in depth unless the physician explicitly asks for a **full-day** refresh (then the product may reuse the **Use Case 1** shape across all slots).

**Latency requirement:** Summary for the **remaining** half of a typical day in under 15 seconds.

**What the agent must not do:** Same bar as **Use Case 1** — no visit order, no "who to prioritize," no new tasks or chart writes, no management or clinical recommendations. Surface only what the schedule and record show for the requested window.

---

### Use Case 7 — No-show sweep

**Trigger:** End of day, or on demand when the physician selects today's missed appointments (no-shows or same-day cancellations they did not see).

**What the agent delivers** (one compact block per missed patient, in the same spirit as the pre-visit briefing and critical flags — facts only, ranked by what is already defined as time-sensitive in the chart):
- Overdue preventive care or unresolved referrals for that patient
- Abnormal labs on file with no clear follow-up in recent notes (same bar as **Use Case 3**)
- Any critical-flag items that would have appeared in a briefing had the visit occurred

Patients with nothing notable get a single line ("No time-sensitive items surfaced") so the sweep stays scannable.

**Latency requirement:** The full list for a typical day (a small number of missed slots) in under 15 seconds. Individual patient expansions, if offered, follow the in-room Q&A window (8 seconds; **Use Case 4**).

**What the agent must not do:** Recommend callbacks, medications, or workup. Surface only what the record already shows; the physician decides whether to act before tomorrow.

---