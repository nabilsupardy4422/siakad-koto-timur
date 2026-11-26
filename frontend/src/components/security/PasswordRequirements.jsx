import { passwordChecks } from './passwordPolicy.js'
import './PasswordRequirements.css'

export default function PasswordRequirements({password, confirmation}) {
  const checks = passwordChecks(password, confirmation)
  return <div className="school-password-policy" role="group" aria-label="Persyaratan password">
    <p className="school-password-policy__title">Persyaratan password</p>
    <ul className="school-password-policy__checks">
      {checks.map(({label,ok}) => <li key={label} className={ok ? 'is-met' : ''}>
        <span aria-hidden="true">{ok ? '✓' : '○'}</span><span>{label}<span className="school-password-policy__sr"> — {ok ? 'terpenuhi' : 'belum terpenuhi'}</span></span>
      </li>)}
    </ul>
    <p className="school-password-policy__count" aria-live="polite">{checks.filter(x=>x.ok).length} dari {checks.length} syarat terpenuhi.</p>
  </div>
}
