import React from 'react'

const Card = ({ icon, title, className, children }) => {
    return (
        <>
            <div className={`px-4 py-3 rounded-t-xl border ${className} bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800`}>
                <div className='flex items-center gap-2 font-semibold text-sm text-slate-700 dark:text-slate-200'>
                    {icon}
                    {title}
                </div>
            </div>
            <div className='bg-white dark:bg-slate-900 rounded-b-xl border-x border-b border-slate-200 dark:border-slate-800'>
                {children}
            </div>
        </>
    )
}

const Table = ({ children }) => {
    return (
        <div className="w-full overflow-hidden overflow-x-auto border-collapse rounded-b-xl">
            <table className="w-full text-sm">
                {children}
            </table>
        </div>
    );
};

const Thead = ({ className, children }) => {
    return (
        <thead className={`${className} border-b border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-900/50`}>{children}</thead>
    );
};

const Tbody = ({ className, children }) => {
    return (
        <tbody className={`${className} divide-y divide-slate-100 bg-white dark:divide-slate-800 dark:bg-slate-900`}>
            {children}
        </tbody>
    );
};

const Td = ({ className, children }) => {
    return (
        <td
            className={`${className} whitespace-nowrap p-4 align-middle text-slate-700 dark:text-slate-300`}
        >
            {children}
        </td>
    );
};

const Th = ({ className, children }) => {
    return (
        <th
            scope="col"
            className={`${className} h-12 px-4 text-left align-middle font-semibold text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400`}
        >
            {children}
        </th>
    );
};

const Empty = ({ colSpan, message, children }) => {
    return (
        <tr>
            <td colSpan={colSpan}>
                <div className="flex items-center justify-center py-16">
                    <div className="text-center">
                        {children}
                        <div className="mt-5">
                            {message}
                        </div>
                    </div>
                </div>
            </td>
        </tr>
    )
}

Table.Card = Card;
Table.Thead = Thead;
Table.Tbody = Tbody;
Table.Td = Td;
Table.Th = Th;
Table.Empty = Empty;

export default Table;
