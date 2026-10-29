<svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($name)
@case('grid')<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>@break
@case('file')<path d="M14 3H5v18h14V8z M14 3v5h5 M8 12h8 M8 16h6"/>@break
@case('bottle')<path d="M10 3h4 M10 3v5l-3 4v9h10v-9l-3-4V3 M7 14h10 M10 17h4"/>@break
@case('cask')<path d="M7 3h10c4 6 4 12 0 18H7C3 15 3 9 7 3z M5 7h14 M5 17h14 M9 3c-2 6-2 12 0 18 M15 3c2 6 2 12 0 18"/>@break
@case('users')<circle cx="9" cy="7" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3 M16 4a3 3 0 0 1 0 6 M18 14a5 5 0 0 1 3 4v3"/>@break
@case('shield')<path d="M12 3 3 7v5c0 5 9 10 9 10s9-5 9-10V7z M8 12l3 3 5-6"/>@break
@case('message')<path d="M21 15a3 3 0 0 1-3 3H9l-6 3V6a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3z M7 8h10 M7 12h7"/>@break
@case('check')<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>@break
@case('arrow')<path d="M5 12h14 m-5-5 5 5-5 5"/>@break
@case('plus')<path d="M12 5v14 M5 12h14"/>@break
@case('search')<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>@break
@default <circle cx="12" cy="12" r="9"/><path d="M12 8v5 M12 16h.01"/>
@endswitch</svg>
