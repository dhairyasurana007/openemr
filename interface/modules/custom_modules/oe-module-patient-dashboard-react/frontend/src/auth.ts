import { User, UserManager, WebStorageStateStore } from "oidc-client-ts";

export type DashboardBootstrap = {
  webRoot: string;
  moduleWebPath: string;
  pid: string;
  patientId: string;
  csrfToken: string;
  apiBase: string;
  timezone: string;
  auth?: {
    issuer?: string;
    clientId?: string;
    scope?: string;
    redirectPath?: string;
  };
};

export type AuthState = {
  status: "disabled" | "ready" | "error";
  accessToken: string | null;
  reason?: string;
};

export function buildRedirectUri(redirectPath: string): string {
  return `${window.location.origin}${redirectPath}`;
}

export function isAuthEnabled(config: DashboardBootstrap): boolean {
  return Boolean(config.auth?.issuer && config.auth.clientId);
}

function hasOidcCallbackParams(url: URL): boolean {
  return url.searchParams.has("code") && url.searchParams.has("state");
}

export async function initializeAuth(config: DashboardBootstrap): Promise<AuthState> {
  if (!isAuthEnabled(config)) {
    return { status: "disabled", accessToken: null, reason: "OIDC config missing" };
  }

  const redirectPath = config.auth?.redirectPath ?? window.location.pathname;
  const manager = new UserManager({
    authority: config.auth?.issuer ?? "",
    client_id: config.auth?.clientId ?? "",
    redirect_uri: buildRedirectUri(redirectPath),
    response_type: "code",
    scope: config.auth?.scope ?? "openid profile",
    automaticSilentRenew: false,
    userStore: new WebStorageStateStore({ store: window.sessionStorage }),
  });

  try {
    const currentUrl = new URL(window.location.href);
    if (hasOidcCallbackParams(currentUrl)) {
      await manager.signinRedirectCallback();
      currentUrl.search = "";
      window.history.replaceState({}, document.title, currentUrl.toString());
    }

    let user: User | null = await manager.getUser();
    if (!user || user.expired) {
      try {
        user = await manager.signinSilent();
      } catch {
        await manager.signinRedirect();
        return { status: "ready", accessToken: null, reason: "Redirecting to OIDC login" };
      }
    }

    if (!user || !user.access_token) {
      await manager.signinRedirect();
      return { status: "ready", accessToken: null, reason: "Redirecting to OIDC login" };
    }

    return { status: "ready", accessToken: user.access_token };
  } catch (error) {
    return { status: "error", accessToken: null, reason: error instanceof Error ? error.message : "Auth failed" };
  }
}
