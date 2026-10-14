import { Link } from '@inertiajs/react';

export default function NavLink({ active = false, className = '', children, ...props }) {
    return (
        <Link
            {...props}
            className={
                'inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none ' +
                (active
                    ? 'border-primary-400 text-slate-900 dark:text-white focus:border-primary-700 dark:focus:border-primary-400 '
                    : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:border-slate-300 dark:hover:border-slate-600 focus:text-slate-700 dark:focus:text-slate-200 focus:border-slate-300 dark:focus:border-slate-600 ') +
                className
            }
        >
            {children}
        </Link>
    );
}
