import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const login = readFileSync(
  new URL("../src/pages/auth/LoginPage.jsx", import.meta.url),
  "utf8",
);
const auth = readFileSync(
  new URL("../src/context/AuthContext.jsx", import.meta.url),
  "utf8",
);
const requireAuth = readFileSync(
  new URL("../src/routes/RequireAuth.jsx", import.meta.url),
  "utf8",
);
const dashboard = readFileSync(
  new URL("../src/pages/dashboard/DashboardPage.jsx", import.meta.url),
  "utf8",
);
const teaching = readFileSync(
  new URL("../src/utils/teachingScope.js", import.meta.url),
  "utf8",
);

test("login selalu kembali ke Dashboard, tidak mewarisi tujuan halaman dari akun sebelumnya", () => {
  assert.match(
    login,
    /const AUTHENTICATED_HOME\s*=\s*['"]\/app\/dashboard['"]/,
  );
  assert.match(login, /const destination\s*=\s*AUTHENTICATED_HOME/);
  assert.doesNotMatch(
    login,
    /location\.state\?\.from|safeDestination|useLocation/,
  );
});

test("login password, login Google, dan pengguna yang sudah login punya tujuan sama", () => {
  assert.match(
    login,
    /await login\(username\.trim\(\), password\)[\s\S]*?navigate\(destination,\s*\{\s*replace:\s*true\s*\}\)/,
  );
  assert.match(
    login,
    /setAuthToken\(token\)[\s\S]*?window\.location\.replace\(destination\)/,
  );
  assert.match(
    login,
    /if\s*\(status\s*===\s*['"]authenticated['"]\)[\s\S]*?return\s*<Navigate to=\{destination\} replace\s*\/>/,
  );
});

test("auth tetap menerima identitas lengkap dari server dan tidak meminjam identitas lain", () => {
  assert.match(auth, /user:\s*data\.user/);
  assert.match(auth, /setAuthToken\(data\.token\)/);
  assert.match(auth, /clearAuthToken\(\)/);
  assert.match(
    teaching,
    /teacherId\s*=\s*user\s*=>\s*user\?\.guru\?\.id\s*\?\?\s*null/,
  );
});

test("akses privat dan dashboard tetap berbasis autentikasi dan role masing-masing", () => {
  assert.match(requireAuth, /status !== ['"]authenticated['"]/);
  for (const role of ["GURU", "SISWA", "TU", "KEPALA_SEKOLAH"])
    assert.ok(dashboard.includes(`'${role}'`));
});
