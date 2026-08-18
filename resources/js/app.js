import Alpine from 'alpinejs';
import Sortable from 'sortablejs';
import './passkeys';
import { registerTicketAttach } from './ticket-attach';
import { registerMapPicker } from './map-picker';
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

// Quill and Leaflet are NOT imported here: each is used on exactly one screen
// (timesheet note modal / attendance-admin map picker) and dynamic-imports
// itself on first use instead of taxing every page's bundle.
// First, so a fault thrown while any of the registrations below run is still reported.
registerSentry();
registerTicketAttach(Alpine);
registerMapPicker(Alpine);
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
