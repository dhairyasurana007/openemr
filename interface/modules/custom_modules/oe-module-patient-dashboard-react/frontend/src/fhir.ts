import type { DashboardBootstrap } from "./auth";

type HumanName = {
  given?: string[];
  family?: string;
};

type Identifier = {
  type?: {
    coding?: Array<{ code?: string }>;
    text?: string;
  };
  value?: string;
};

type FhirReference = {
  display?: string;
  reference?: string;
};

export type PatientResource = {
  name?: HumanName[];
  birthDate?: string;
  gender?: string;
  active?: boolean;
  identifier?: Identifier[];
};

type AllergyIntoleranceResource = {
  code?: { text?: string; coding?: Array<{ display?: string }> };
  criticality?: string;
};

type ConditionResource = {
  code?: { text?: string; coding?: Array<{ display?: string }> };
  clinicalStatus?: { coding?: Array<{ code?: string; display?: string }> };
};

type MedicationRequestResource = {
  medicationCodeableConcept?: { text?: string; coding?: Array<{ display?: string }> };
  status?: string;
  authoredOn?: string;
};

type CareTeamResource = {
  participant?: Array<{
    member?: FhirReference;
    role?: Array<{ text?: string; coding?: Array<{ display?: string }> }>;
  }>;
};

type FhirBundle<T> = {
  entry?: Array<{ resource?: T }>;
};

export type PatientSummary = {
  name: string;
  dob: string;
  sex: string;
  mrn: string;
  activeStatus: string;
};

export type DashboardItem = {
  primary: string;
  secondary?: string;
};

export type DashboardData = {
  header: PatientSummary;
  allergies: DashboardItem[];
  problemList: DashboardItem[];
  medications: DashboardItem[];
  prescriptions: DashboardItem[];
  careTeam: DashboardItem[];
};

function fetchJson<T>(url: string, token: string | null, csrfToken: string): Promise<T> {
  const headers: Record<string, string> = {
    Accept: "application/fhir+json",
    APICSRFTOKEN: csrfToken,
  };
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  return fetch(url, {
    method: "GET",
    headers,
    credentials: "include",
  }).then(async (response) => {
    if (!response.ok) {
      throw new Error(`FHIR request failed (${response.status}) for ${url}`);
    }
    return response.json() as Promise<T>;
  });
}

function encode(patientId: string): string {
  return encodeURIComponent(patientId);
}

export function resourceLabel(text?: string, coding?: Array<{ display?: string }>): string {
  if (text) {
    return text;
  }
  const display = coding?.find((item) => Boolean(item.display))?.display;
  return display ?? "Unknown";
}

export function patientDisplayName(patient: PatientResource): string {
  const primaryName = patient.name?.[0];
  if (!primaryName) {
    return "Unknown";
  }
  const parts = [...(primaryName.given ?? []), primaryName.family].filter(Boolean);
  return parts.join(" ");
}

export function patientMrn(patient: PatientResource): string {
  const fromTypedIdentifier = patient.identifier?.find((identifier) =>
    identifier.type?.coding?.some((coding) => coding.code?.toUpperCase() === "MR")
  )?.value;
  if (fromTypedIdentifier) {
    return fromTypedIdentifier;
  }
  return patient.identifier?.find((identifier) => Boolean(identifier.value))?.value ?? "Unknown";
}

function toPatientSummary(patient: PatientResource): PatientSummary {
  return {
    name: patientDisplayName(patient),
    dob: patient.birthDate ?? "Unknown",
    sex: patient.gender ?? "Unknown",
    mrn: patientMrn(patient),
    activeStatus: patient.active === false ? "Inactive" : "Active",
  };
}

function bundleResources<T>(bundle: FhirBundle<T>): T[] {
  return (bundle.entry ?? [])
    .map((entry) => entry.resource)
    .filter((resource): resource is T => Boolean(resource));
}

function mapAllergies(resources: AllergyIntoleranceResource[]): DashboardItem[] {
  return resources.map((resource) => ({
    primary: resourceLabel(resource.code?.text, resource.code?.coding),
    secondary: resource.criticality ? `Criticality: ${resource.criticality}` : undefined,
  }));
}

function mapConditions(resources: ConditionResource[]): DashboardItem[] {
  return resources.map((resource) => ({
    primary: resourceLabel(resource.code?.text, resource.code?.coding),
    secondary: resource.clinicalStatus?.coding?.[0]?.display ?? resource.clinicalStatus?.coding?.[0]?.code,
  }));
}

function mapMedicationRequests(resources: MedicationRequestResource[]): DashboardItem[] {
  return resources.map((resource) => ({
    primary: resourceLabel(resource.medicationCodeableConcept?.text, resource.medicationCodeableConcept?.coding),
    secondary: resource.status ?? undefined,
  }));
}

function mapPrescriptions(resources: MedicationRequestResource[]): DashboardItem[] {
  return resources.map((resource) => ({
    primary: resourceLabel(resource.medicationCodeableConcept?.text, resource.medicationCodeableConcept?.coding),
    secondary: resource.authoredOn ? `Authored: ${resource.authoredOn}` : resource.status,
  }));
}

function mapCareTeam(resources: CareTeamResource[]): DashboardItem[] {
  const participants = resources.flatMap((resource) => resource.participant ?? []);
  return participants.map((participant) => ({
    primary: participant.member?.display ?? participant.member?.reference ?? "Unknown Member",
    secondary: participant.role?.[0]?.text ?? participant.role?.[0]?.coding?.[0]?.display,
  }));
}

export async function fetchDashboardData(config: DashboardBootstrap, token: string | null): Promise<DashboardData> {
  const patientId = encode(config.patientId);
  const csrfToken = config.csrfToken;
  const patient = await fetchJson<PatientResource>(`${config.apiBase}/fhir/Patient/${patientId}`, token, csrfToken);

  const [allergiesBundle, conditionsBundle, medicationsBundle, prescriptionsBundle, careTeamBundle] = await Promise.all([
    fetchJson<FhirBundle<AllergyIntoleranceResource>>(`${config.apiBase}/fhir/AllergyIntolerance?patient=${patientId}`, token, csrfToken),
    fetchJson<FhirBundle<ConditionResource>>(`${config.apiBase}/fhir/Condition?patient=${patientId}`, token, csrfToken),
    fetchJson<FhirBundle<MedicationRequestResource>>(`${config.apiBase}/fhir/MedicationRequest?patient=${patientId}&status=active`, token, csrfToken),
    fetchJson<FhirBundle<MedicationRequestResource>>(`${config.apiBase}/fhir/MedicationRequest?patient=${patientId}`, token, csrfToken),
    fetchJson<FhirBundle<CareTeamResource>>(`${config.apiBase}/fhir/CareTeam?patient=${patientId}`, token, csrfToken),
  ]);

  return {
    header: toPatientSummary(patient),
    allergies: mapAllergies(bundleResources(allergiesBundle)),
    problemList: mapConditions(bundleResources(conditionsBundle)),
    medications: mapMedicationRequests(bundleResources(medicationsBundle)),
    prescriptions: mapPrescriptions(bundleResources(prescriptionsBundle)),
    careTeam: mapCareTeam(bundleResources(careTeamBundle)),
  };
}
