export default function AuthBotGuardFields({ botGuard, data, setData }) {
    if (!botGuard?.enabled) {
        return null;
    }

    const honeypotField = botGuard.honeypot_field || "company_website";
    const tokenField = botGuard.token_field || "bot_guard_token";

    return (
        <div className="hidden" aria-hidden="true">
            <input
                type="text"
                tabIndex={-1}
                autoComplete="off"
                name={honeypotField}
                value={data?.[honeypotField] ?? ""}
                onChange={(event) => setData(honeypotField, event.target.value)}
            />
            <input
                type="hidden"
                name={tokenField}
                value={data?.[tokenField] ?? ""}
                readOnly
            />
        </div>
    );
}
