# Bugs Found in Original OpenEMR

This note is for readers who are not software developers. It describes what went wrong for **people using the system**, in plain language.

---

## Issue 1: New patient signup could show a generic “server error” instead of useful feedback

**What you would notice:** While finishing **self-registration in the patient portal** (creating your new patient profile after email verification), the process could fail with a **broken or generic error** (often described as a “500” or “something went wrong” page) even when the real problem was just a **form mistake** (missing field, invalid value, etc.).

**In short:** The system tried to report form errors to you but hit an internal programming bug while doing so, which made the failure look worse than it was.

---

## Issue 2: Harder to complete signup if you did not choose a doctor

**What you would notice:** On the same **new patient profile** step, leaving **“Current Physician”** blank could **block you from saving**, even though choosing a doctor may feel optional.

**In short:** The screen did not send “no doctor selected” in the same safe way it did for other optional choices, so the server rejected the save.

---

## Summary

| What went wrong (for users) | Brief technical note |
|-----------------------------|------------------------|
| Portal signup could crash on form errors instead of showing clear validation messages | A line of server code accidentally treated a list of errors like plain text. |
| Signup could fail when “Current Physician” was left empty | One field was not normalized like similar optional fields before the server checked the form. |

---

*These items were found while reviewing the patient portal registration flow in this OpenEMR codebase.*
