# USERS.md — Clinical Co-Pilot

## Target User

**Family physician**, small-to-mid practice, 20 patients per day in 15-minute slots. Uses OpenEMR as their primary EHR.

---

## Workflow

### Pre-visit intake (nurse)
Before the physician enters the room, a nurse visits the patient and handles intake:
- Records vitals (blood pressure, weight, temperature, pulse)
- Updates the medication list for any changes since the last visit
- Documents the chief complaint and reason for today's visit
- Notes any new symptoms or concerns the patient raises

This intake is documented directly into OpenEMR and is available to the physician immediately.

### Between patients (physician)
The physician finishes documenting the previous visit and has approximately 90 seconds before walking into the next room. They open the patient encounter in OpenEMR.

**This is the moment the agent enters the workflow.**

The agent's job is to synthesize two things:
- **What the nurse just captured** — today's vitals, chief complaint, medication updates
- **Everything in the patient's history** — prior labs, past visit notes, active diagnoses, current medications, open care gaps

The physician needs this synthesis in a single paragraph they can read in 20 seconds. They do not have time to read three encounter notes, cross-reference labs against the medication list, and check for open referrals separately.

### In the room (physician)
The physician is with the patient. A question comes up that requires pulling something specific from the chart — a lab trend, a medication dose, a referral status. They need the answer in under 10 seconds without breaking the conversation.

### End of day (physician)
The physician has seen most of their patients. One or two may have missed appointments. They want to know if there is anything time-sensitive in those charts before tomorrow.

---

## Use Cases

---

### Use Case 1 — Pre-visit briefing

**Trigger:** Physician opens a patient encounter in OpenEMR.

**What the agent delivers** (within 5 seconds of encounter open):
- Chief complaint for today's visit (from nurse intake)
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

### Use Case 2 — In-room follow-up question

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

### Use Case 3 — Critical flag surfacing

**Trigger:** Automatically included in the pre-visit briefing, or surfaced on-demand.

**What the agent flags proactively:**
- Potential drug-drug or drug-condition interactions in the current medication list
- Abnormal lab values not addressed in the last visit note
- Overdue preventive care (Pap smear, colonoscopy, flu vaccine)
- Referrals ordered with no result on file after 60+ days

Flags include context — not just "drug interaction detected" but which medications, what the relevant condition is, and why the combination matters for this patient.

**Latency requirement:** Flags are part of the pre-visit briefing (5-second window). On-demand flag queries follow the in-room Q&A window (8 seconds).

**What the agent must not do:** Tell the physician what to do about the flag.

---