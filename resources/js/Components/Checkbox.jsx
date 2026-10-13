export default function Checkbox({ className = '', ...props }) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'rounded border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-primary-600 shadow-sm focus:ring-primary-500 ' +
                className
            }
        />
    );
}
