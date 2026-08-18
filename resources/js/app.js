import Alpine from 'alpinejs';
import Sortable from 'sortablejs';
import './passkeys';
import { registerTicketAttach } from './ticket-attach';
import { registerMapPicker } from './map-picker';
import { registerMapView } from './map-view';
import { registerNotifier } from './notifier';
import { registerOrgChart } from './org-chart';
import { registerPartialNav } from './partial-nav';
import { registerPasskeyManager } from './passkey-manager';
import { registerRolesAdmin } from './roles-admin';
import { registerSentry } from './sentry';
import { registerServiceWorker } from './pwa';
import { registerSidebarRail } from './sidebar-rail';
import { registerTeamBoard } from './team-board';
import { registerTimesheetCapture } from './timesheet-capture';
import { registerToast } from './toast';
import { registerKnowledgeAttach } from './knowledge-attach';
import { registerKnowledgeCard } from './knowledge-card';
import { registerTotCard } from './tot-card';
import { registerTotRoster } from './tot-roster';
import { registerUploadFilenameSanitizer } from './upload-filename';
import { registerWorkBoard } from './work-board';

window.Alpine = Alpine;
window.Sortable = Sortable;

// Vite wraps every dynamic import() and dispatches this event when a lazy chunk 404s —
// the file existed at build time but a later deploy removed it. This app never does a
// full-page reload between screens (see CLAUDE.md), so a tab left open across a deploy
// hits this the next time it lazy-loads Quill or Leaflet. Reload once to pick up the
// current build.
// ponytail: sessionStorage guard means only the FIRST stale-chunk hit per tab
// auto-reloads; a second deploy landing in the same still-open tab needs a manual
// refresh. Upgrade to a time-based key if that turns out to matter in practice.
window.addEventListener('vite:preloadError', (event) => {
    event.preventDefault();
    if (sessionStorage.getItem('chunk-reload')) {
        return;
    }
    sessionStorage.setItem('chunk-reload', '1');
    window.location.reload();
});

// Quill and Leaflet are NOT imported here: each is used on exactly one screen
// (timesheet note modal / attendance-admin map picker) and dynamic-imports
// itself on first use instead of taxing every page's bundle.
// First, so a fault thrown while any of the registrations below run is still reported.
registerSentry();
registerTicketAttach(Alpine);
registerMapPicker(Alpine);
registerMapView(Alpine);
registerNotifier(Alpine);
registerOrgChart(Alpine);
registerPartialNav();
registerPasskeyManager(Alpine);
registerRolesAdmin(Alpine);
registerSidebarRail(Alpine);
registerTeamBoard(Alpine);
registerTimesheetCapture(Alpine);
registerToast(Alpine);
registerKnowledgeAttach(Alpine);
registerKnowledgeCard(Alpine);
registerTotCard(Alpine);
registerTotRoster(Alpine);
registerUploadFilenameSanitizer();
registerWorkBoard(Alpine);
registerServiceWorker();

Alpine.start();
