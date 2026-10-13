import React from "react";
import { Menu, Transition } from "@headlessui/react";
import { router } from "@inertiajs/react";
import { IconLogout, IconRotate } from "@tabler/icons-react";
import { useForm } from "@inertiajs/react";
import axios from "axios";
import i18n from "@/i18n";
export default function AuthDropdown({ auth }) {
    // define usefrom
    const { post } = useForm();

    // define function logout
    const logout = async (e) => {
        e.preventDefault();

        post(route("logout"));
    };

    const resetTours = (e) => {
        e.preventDefault();

        // ponytail: plain axios — Inertia router rejects the JSON-only response
        axios
            .post(route("tours.reset"), {}, { headers: { Accept: "application/json" } })
            .then(() => router.reload({ only: ["auth"] }));
    };

    const avatarUrl = auth.user.avatar;
    const userInitial =
        auth.user.name?.charAt(0)?.toUpperCase() ??
        auth.user.email?.charAt(0)?.toUpperCase() ??
        "?";

    return (
        <Menu className="relative z-10" as="div">
            <Menu.Button
                aria-label={auth.user.name}
                className="flex min-h-touch min-w-touch items-center justify-center rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40"
            >
                {avatarUrl ? (
                    <img
                        src={avatarUrl}
                        alt={auth.user.name}
                        className="w-10 h-10 rounded-full object-cover"
                    />
                ) : (
                    <div className="w-10 h-10 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center font-semibold">
                        {userInitial}
                    </div>
                )}
            </Menu.Button>
            <Transition
                enter="transition duration-100 ease-out"
                enterFrom="transform scale-95 opacity-0"
                enterTo="transform scale-100 opacity-100"
                leave="transition duration-75 ease-out"
                leaveFrom="transform scale-100 opacity-100"
                leaveTo="transform scale-95 opacity-0"
            >
                <Menu.Items className="absolute rounded-lg w-48 border mt-2 py-2 right-0 z-[100] bg-white dark:bg-gray-950 dark:border-gray-900">
                    <div className="flex flex-col gap-1.5 divide-y divide-gray-100 dark:divide-gray-900">
                        <Menu.Item>
                            <button
                                onClick={resetTours}
                                className="px-3 py-2.5 min-h-touch w-full text-sm flex items-center gap-2 text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200"
                            >
                                <IconRotate strokeWidth={"1.5"} size={"20"} />
                                {i18n.t("tour.reset")}
                            </button>
                        </Menu.Item>
                        <Menu.Item>
                            <button
                                onClick={logout}
                                className="px-3 py-2.5 min-h-touch w-full text-sm flex items-center gap-2 text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200"
                            >
                                <IconLogout strokeWidth={"1.5"} size={"20"} />
                                Logout
                            </button>
                        </Menu.Item>
                    </div>
                </Menu.Items>
            </Transition>
        </Menu>
    );
}
