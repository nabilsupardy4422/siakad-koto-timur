// Browser capability check only. Google Cloud still validates the exact origin,
// and Laravel still validates credentials, account status and authorization.
export function getGoogleEnvironment(origin, clientId) {
  let url
  try { url = new URL(origin) } catch { return { available: false, reason: 'unsupported_origin' } }
  const host = url.hostname.toLowerCase()
  const loopback = host === 'localhost' || host === '127.0.0.1'
  const http = url.protocol === 'http:' || url.protocol === 'https:'
  // Google does not accept raw IPs (except supported loopback origins).
  // A syntactically eligible HTTPS domain must still be registered in Google Cloud.
  const dnsHost = host.includes('.') && !/^[\d.]+$/.test(host) && !host.includes(':') && !host.endsWith('.')
  if (!http || url.origin !== origin || !(loopback || (url.protocol === 'https:' && dnsHost))) {
    return { available: false, reason: 'unsupported_origin' }
  }
  if (typeof clientId !== 'string' || !clientId.trim()) {
    return { available: false, reason: 'not_configured' }
  }
  return { available: true, reason: null }
}

export function googleUnavailableMessage(environment, purpose = 'login') {
  if (environment.available) return ''
  const alternative = purpose === 'login'
    ? 'Gunakan username dan password untuk masuk.'
    : 'Gunakan localhost yang terdaftar atau domain HTTPS resmi sekolah, atau hubungi Tata Usaha.'
  return environment.reason === 'not_configured'
    ? `Layanan Google belum dikonfigurasi. ${alternative}`
    : `Layanan Google tidak tersedia pada alamat ini. ${alternative}`
}
