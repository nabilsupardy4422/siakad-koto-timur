import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {
  contactChanges,
  genderLabel,
  placementLabel,
  profileValue,
  passwordChecks,
} from "../src/pages/profile/personalProfileLogic.js";
const src = readFileSync(
  new URL("../src/pages/profile/PersonalProfileWorkspace.jsx", import.meta.url),
  "utf8",
);
const page = readFileSync(
  new URL("../src/pages/profile/ProfilePage.jsx", import.meta.url),
  "utf8",
);
test("only changed allowlisted contacts are sent and preserve leading zeros", () => {
  assert.deepEqual(
    contactChanges(
      { no_telepon: "01", alamat: "old" },
      { no_telepon: " 001 ", alamat: "old", nip: "fake" },
      "v",
    ),
    { profile_version: "v", no_telepon: "001" },
  );
  assert.equal(
    contactChanges(
      { no_telepon: "01", alamat: null },
      { no_telepon: "01", alamat: "" },
      "v",
    ),
    null,
  );
});
test("clear a contact explicitly rather than accidentally overwriting missing fields", () => {
  assert.deepEqual(
    contactChanges(
      { no_telepon: "01", alamat: "old" },
      { no_telepon: "01", alamat: " " },
      "v",
    ),
    { profile_version: "v", alamat: null },
  );
});
test("zero is not missing and gender is normalized only for display", () => {
  assert.equal(profileValue(0), "0");
  assert.equal(profileValue(null), "Belum diisi");
  assert.equal(profileValue("   "), "Belum diisi");
  assert.equal(genderLabel("P"), "Perempuan");
});
test("placement distinguishes current, future, historical, and conflicts", () => {
  assert.equal(
    placementLabel({ status: "effective", class_name: "X A" }),
    "X A",
  );
  assert.equal(
    placementLabel({ status: "future", class_name: "X A" }),
    "Penempatan belum berlaku",
  );
  assert.equal(
    placementLabel({ status: "historical", class_name: "X A" }),
    "Penempatan telah berakhir",
  );
  assert.match(placementLabel({ status: "conflict" }), /Konflik/);
});
test("password check rejects weak, mismatched and oversized passwords", () => {
  assert.equal(
    passwordChecks("weak", "weak").every((x) => x.ok),
    false,
  );
  assert.equal(
    passwordChecks("StrongPassword#2026", "different").every((x) => x.ok),
    false,
  );
  assert.equal(
    passwordChecks("StrongPassword#2026", "StrongPassword#2026").every(
      (x) => x.ok,
    ),
    true,
  );
  assert.equal(
    passwordChecks("Ab1!" + "é".repeat(40), "Ab1!" + "é".repeat(40)).every(
      (x) => x.ok,
    ),
    false,
  );
});
test("profile keeps existing Google and private photo flow and scoped API", () => {
  assert.match(page, /apiClient\.get\('\/profile'\)/);
  assert.match(page, /googleAccountApi\.status\(\)/);
  assert.match(page, /ProfilePhotoCard userId=/);
  assert.match(page, /await logout\(\)/);
  assert.match(src, /apiClient\.put\('\/profile',payload\)/);
  assert.match(src, /apiClient\.put\('\/profile\/password',values\)/);
  assert.match(src, /stale\?/);
});
