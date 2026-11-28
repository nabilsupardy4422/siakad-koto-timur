// Keep in sync with App\Support\SchoolPasswordPolicy. No credential storage.
export const PASSWORD_POLICY_MESSAGE = 'Gunakan minimal 12 karakter, maksimal 72 byte, huruf besar dan kecil, angka, serta simbol. Spasi bukan simbol dan karakter kontrol tidak diperbolehkan.'
export function passwordChecks(password, confirmation) {
  const checks = [
    {label:'Minimal 12 karakter, maksimal 72 byte', ok:[...password].length >= 12 && new TextEncoder().encode(password).length <= 72},
    {label:'Huruf besar dan kecil', ok:/\p{Lu}/u.test(password) && /\p{Ll}/u.test(password)},
    {label:'Angka', ok:/\p{N}/u.test(password)},
    {label:'Simbol (bukan spasi)', ok:/[\p{P}\p{S}]/u.test(password)},
    {label:'Tanpa karakter kontrol', ok:password.length > 0 && !/[\p{Cc}\p{Cs}]/u.test(password)},
  ]
  if (confirmation !== undefined) checks.push({label:'Konfirmasi sesuai', ok:password.length > 0 && password === confirmation})
  return checks
}
export const isStrongPassword = password => passwordChecks(password).every(x => x.ok)
