import Alpine from 'alpinejs';
import Sortable from 'sortablejs';
import './passkeys';
import { registerFeedbackAttach } from './feedback-attach';
import { registerMapPicker } from './map-picker';
import { registerOrgChart } from './org-chart';
import { registerRolesAdmin } from './roles-admin';
import { registerTimesheetCapture } from './timesheet-capture';
import { registerWorkBoard } from './work-board';

window.Alpine = Alpine;
window.Sortable = Sortable;

// Quill and Leaflet are NOT imported here: each is used on exactly one screen
// (timesheet note modal / attendance-admin map picker) and dynamic-imports
// itself on first use instead of taxing every page's bundle.
registerFeedbackAttach(Alpine);
registerMapPicker(Alpine);
registerOrgChart(Alpine);
registerRolesAdmin(Alpine);
registerTimesheetCapture(Alpine);
registerWorkBoard(Alpine);

Alpine.start();
