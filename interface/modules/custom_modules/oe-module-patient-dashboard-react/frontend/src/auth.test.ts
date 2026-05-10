import { describe, expect, it } from "vitest";
import { buildRedirectUri, initializeAuth, isAuthEnabled } from "./auth";

describe("auth helpers", () => {
  it("builds absolute redirect URIs from path", () => {
    expect(buildRedirectUri("/callback")).toBe(`${window.location.origin}/callback`);
  });

  it("detects whether OIDC config is present", () => {
    expect(
      isAuthEnabled({
        webRoot: "/openemr",
        moduleWebPath: "/module",
        patientId: "1",
        csrfToken: "token",
        apiBase: "/api",
        timezone: "UTC",
        auth: { issuer: "https://issuer.example", clientId: "client-id" },
      })
    ).toBe(true);
    expect(
      isAuthEnabled({
        webRoot: "/openemr",
        moduleWebPath: "/module",
        patientId: "1",
        csrfToken: "token",
        apiBase: "/api",
        timezone: "UTC",
      })
    ).toBe(false);
  });

  it("returns disabled state when OIDC settings are absent", async () => {
    const result = await initializeAuth({
      webRoot: "/openemr",
      moduleWebPath: "/module",
      patientId: "1",
      csrfToken: "token",
      apiBase: "/api",
      timezone: "UTC",
      auth: {},
    });
    expect(result.status).toBe("disabled");
    expect(result.accessToken).toBeNull();
  });
});
