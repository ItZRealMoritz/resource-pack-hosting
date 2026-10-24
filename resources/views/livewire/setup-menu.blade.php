<div>
    <style>
        @keyframes rph-window-in {
            0% { opacity: 0; transform: translateY(28px) scale(.94); filter: blur(6px); }
            60% { opacity: 1; filter: blur(0); }
            100% { opacity: 1; transform: translateY(0) scale(1); filter: blur(0); }
        }

        @keyframes rph-rise {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes rph-icon-pop {
            0% { transform: scale(.4) rotate(-12deg); opacity: 0; }
            70% { transform: scale(1.12) rotate(3deg); opacity: 1; }
            100% { transform: scale(1) rotate(0); }
        }

        @keyframes rph-glow {
            0%, 100% { box-shadow: 0 0 0 0 color-mix(in oklab, var(--primary-500) 45%, transparent); }
            50% { box-shadow: 0 0 0 10px color-mix(in oklab, var(--primary-500) 0%, transparent); }
        }

        .rph-setup-window {
            animation: rph-window-in .65s cubic-bezier(.16, 1, .3, 1) both;
            overflow: hidden;
        }

        .rph-setup-window::before {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-400), var(--primary-600), var(--primary-400));
            background-size: 200% 100%;
            animation: rph-rise .6s ease-out both;
        }

        .rph-setup-window .fi-modal-icon-bg {
            animation: rph-icon-pop .7s .15s cubic-bezier(.34, 1.56, .64, 1) both, rph-glow 2.4s 1s ease-in-out infinite;
        }

        .rph-setup-window .fi-modal-heading,
        .rph-setup-window .fi-modal-description {
            animation: rph-rise .5s .2s cubic-bezier(.16, 1, .3, 1) both;
        }

        .rph-setup-window .rph-section {
            animation: rph-rise .55s cubic-bezier(.16, 1, .3, 1) both;
        }

        .rph-setup-window .rph-section-1 { animation-delay: .3s; }
        .rph-setup-window .rph-section-2 { animation-delay: .42s; }

        .rph-setup-window .fi-modal-footer {
            animation: rph-rise .5s .55s cubic-bezier(.16, 1, .3, 1) both;
        }

        @media (prefers-reduced-motion: reduce) {
            .rph-setup-window,
            .rph-setup-window * {
                animation: none !important;
            }
        }
    </style>

    <div wire:init="openSetup"></div>

    <x-filament-actions::modals />
</div>
