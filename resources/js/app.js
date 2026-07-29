import Alpine from 'alpinejs';
import Sortable from 'sortablejs';
import './passkeys';
import { registerFeedbackAttach } from './feedback-attach';
import { registerMapPicker } from './map-picker';
import { registerNotifier } from './notifier';
import { registerOrgChart } from './org-chart';
import { registerPartialNav } from './partial-nav';
import { registerRolesAdmin } from './roles-admin';
import { registerServiceWorker } from './pwa';
import { registerSidebarRail } from './sidebar-rail';
import { registerTeamBoard } from './team-board';
import { registerTimesheetCapture } from './timesheet-capture';
import { registerToast } from './toast';
import { registerTotCard } from './tot-card';
import { registerTotRoster } from './tot-roster';
import { registerUploadFilenameSanitizer } from './upload-filename';
import { registerWorkBoard } from './work-board';

window.Alpine = Alpine;
window.Sortable = Sortable;

// Quill and Leaflet are NOT imported here: each is used on exactly one screen
// (timesheet note modal / attendance-admin map picker) and dynamic-imports
// itself on first use instead of taxing every page's bundle.
registerFeedbackAttach(Alpine);
registerMapPicker(Alpine);
registerNotifier(Alpine);
registerOrgChart(Alpine);
registerPartialNav();
registerRolesAdmin(Alpine);
registerSidebarRail(Alpine);
registerTeamBoard(Alpine);
registerTimesheetCapture(Alpine);
registerToast(Alpine);
registerTotCard(Alpine);
registerTotRoster(Alpine);
registerUploadFilenameSanitizer();
registerWorkBoard(Alpine);
registerServiceWorker();

Alpine.start();
