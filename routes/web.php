<?php

use App\Http\Controllers\AchievementController;
use App\Http\Controllers\ActivationController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AttendanceAdminController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\BenefitController;
use App\Http\Controllers\CaseController;
use App\Http\Controllers\ClaimController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\ForcePasswordChangeController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\HandbookController;
use App\Http\Controllers\HelpdeskController;
use App\Http\Controllers\IdeaController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\KpiController;
use App\Http\Controllers\LearningController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LeaveSetupController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OffboardingController;
use App\Http\Controllers\OidcController;
use App\Http\Controllers\OnboardingContentController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrgController;
use App\Http\Controllers\OvertimeController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PayrollExportController;
use App\Http\Controllers\PettyCashController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ProbationController;
use App\Http\Controllers\ProfileTestController;
use App\Http\Controllers\RecruitmentController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResignationController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SharedResourceController;
use App\Http\Controllers\ShiftSwapController;
use App\Http\Controllers\SkillController;
use App\Http\Controllers\SuperAdmin\CompanyController as SuperCompanyController;
use App\Http\Controllers\SuperAdmin\FeatureController;
use App\Http\Controllers\SurveyController;
use App\Http\Controllers\TimesheetAdminController;
use App\Http\Controllers\TimesheetController;
use App\Http\Controllers\TrainingController;
use App\Http\Controllers\TravelController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\WelcomeWizardController;
use App\Http\Controllers\WellnessController;
use App\Http\Controllers\WorkforceController;
use App\Http\Controllers\WorkItemController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Entry: guests → Fortify login (custom view), authed users → tenant select.
Route::get('/', fn () => Auth::check() ? redirect()->route('tenant.select') : redirect('/login'));

// Enterprise SSO (OIDC, authorization-code flow). Guest-accessible; the controller
// 404s when OIDC isn't configured. Sign-in only — no tenant/role is ever granted here.
Route::get('/auth/oidc/redirect', [OidcController::class, 'redirect'])->name('oidc.redirect');
Route::get('/auth/oidc/callback', [OidcController::class, 'callback'])->name('oidc.callback');

// Company-branded login portal. The standard sign-in form dressed in a company's
// logo, colours and welcome message; remembers the intended workspace so a
// successful sign-in drops the member straight into it. Unknown slugs 404.
Route::get('/login/{tenant:slug}', [AppController::class, 'brandedLogin'])->name('login.branded');

// Account activation via signed, expiring link (alternative to the one-time password).
// Guest-accessible; the signature is validated before the user is shown the form.
Route::get('/activate/{user}', [ActivationController::class, 'show'])->middleware('signed')->name('activation.show');
Route::post('/activate/{user}', [ActivationController::class, 'update'])->middleware('signed')->name('activation.update');

Route::middleware('auth')->group(function () {
    // First-sign-in password rotation for invited members (I-008). Outside the tenant
    // group so a freshly-invited user can rotate before selecting a tenant. The
    // ForcePasswordChange middleware funnels every other route here until done.
    Route::get('/password/change', [ForcePasswordChangeController::class, 'show'])->name('password.change');
    Route::post('/password/change', [ForcePasswordChangeController::class, 'update'])->name('password.change.update');

    Route::get('/tenant', [AppController::class, 'tenantSelect'])->name('tenant.select');
    Route::get('/tenant/{tenant:slug}', [AppController::class, 'enterTenant'])->name('tenant.enter');

    // Cross-tenant provisioning console — super-admins only. Sits outside the tenant
    // group because a super-admin operates above any single company.
    Route::middleware('super.admin')->prefix('admin')->name('superadmin.')->group(function () {
        Route::get('/companies', [SuperCompanyController::class, 'index'])->name('companies.index');
        Route::get('/companies/new', [SuperCompanyController::class, 'create'])->name('companies.create');
        Route::post('/companies', [SuperCompanyController::class, 'store'])->name('companies.store');
        Route::get('/companies/{tenant:slug}/edit', [SuperCompanyController::class, 'edit'])->name('companies.edit');
        Route::get('/companies/{tenant:slug}', [SuperCompanyController::class, 'show'])->name('companies.show');
        Route::post('/companies/{tenant:slug}', [SuperCompanyController::class, 'update'])->name('companies.update');
        Route::post('/companies/{tenant:slug}/category', [SuperCompanyController::class, 'updateCategory'])->name('companies.category');
        Route::post('/companies/{tenant:slug}/status', [SuperCompanyController::class, 'setStatus'])->name('companies.status');
        Route::post('/companies/{tenant:slug}/members', [SuperCompanyController::class, 'assignMember'])->name('companies.members.assign');
        Route::get('/companies/{tenant:slug}/features', [FeatureController::class, 'show'])->name('companies.features');
        Route::post('/companies/{tenant:slug}/features', [FeatureController::class, 'update'])->name('companies.features.update');
    });

    // Everything inside the shell is tenant-scoped. company.active blocks suspended/
    // expired companies; module.enabled 404s any /app/* path (screen OR write route)
    // whose owning module is disabled for the tenant.
    Route::middleware(['tenant', 'company.active', 'not.archived', 'module.enabled'])->group(function () {
        // Write-paths (state-changing) — defined before the catch-all screen route.
        Route::post('/app/leave', [LeaveController::class, 'store'])->name('leave.store');
        Route::post('/app/leave/{leaveRequest}/verify', [LeaveController::class, 'verify'])->name('leave.verify');
        Route::post('/app/leave/{leaveRequest}/approve', [LeaveController::class, 'approve'])->name('leave.approve');
        Route::post('/app/leave/{leaveRequest}/reject', [LeaveController::class, 'reject'])->name('leave.reject');
        Route::post('/app/leave/bulk-verify', [LeaveController::class, 'bulkVerify'])->name('leave.bulk-verify');
        Route::post('/app/leave/bulk-approve', [LeaveController::class, 'bulkApprove'])->name('leave.bulk-approve');
        Route::get('/app/leave/{leaveRequest}/attachment', [LeaveController::class, 'attachment'])->name('leave.attachment');
        Route::post('/app/leave-setup', [LeaveSetupController::class, 'save'])->name('leave.setup.save');
        // Leave-type master list managed on the Leave Setup screen. 'standard' is declared
        // before the {leaveType} wildcard so it isn't parsed as an id.
        Route::post('/app/leave-setup/types', [LeaveSetupController::class, 'storeLeaveType'])->name('leave.types.store');
        Route::post('/app/leave-setup/types/standard', [LeaveSetupController::class, 'loadStandardTypes'])->name('leave.types.standard');
        Route::post('/app/leave-setup/types/{leaveType}', [LeaveSetupController::class, 'updateLeaveType'])->name('leave.types.update');
        Route::post('/app/leave-setup/types/{leaveType}/delete', [LeaveSetupController::class, 'deleteLeaveType'])->name('leave.types.delete');
        // Public holidays managed alongside leave types on the Leave Setup screen.
        Route::post('/app/leave-setup/holidays', [LeaveSetupController::class, 'storeHoliday'])->name('holiday.store');
        Route::post('/app/leave-setup/holidays/standard', [LeaveSetupController::class, 'loadStandardHolidays'])->name('holiday.standard');
        Route::post('/app/leave-setup/holidays/{holiday}/delete', [LeaveSetupController::class, 'deleteHoliday'])->name('holiday.delete');
        Route::post('/app/attendance/clock', [AttendanceController::class, 'clock'])->name('attendance.clock');
        // Auth-gated clock selfie stream from the private disk — {slot} is 'in' or 'out' (AK-SEC-05).
        Route::get('/app/attendance/photos/{record}/{slot}', [AttendanceController::class, 'photo'])->name('attendance.photo');
        // Attendance setup (geofence + work arrangements) — privileged; screen GET is role-gated in AppController.
        Route::post('/app/attendance-admin/branches/{branch}', [AttendanceAdminController::class, 'updateBranch'])->name('attendance.admin.branch');
        Route::post('/app/attendance-admin/sites', [AttendanceAdminController::class, 'storeSite'])->name('attendance.admin.sites.store');
        Route::post('/app/attendance-admin/sites/{site}', [AttendanceAdminController::class, 'updateSite'])->name('attendance.admin.sites.update');
        Route::post('/app/attendance-admin/sites/{site}/delete', [AttendanceAdminController::class, 'deleteSite'])->name('attendance.admin.sites.delete');
        Route::post('/app/attendance-admin/staff/{employee}', [AttendanceAdminController::class, 'updateEmployee'])->name('attendance.admin.staff');
        Route::post('/app/attendance-admin/staff/{employee}/home', [AttendanceAdminController::class, 'updateHome'])->name('attendance.admin.home');
        Route::post('/app/attendance-admin/wfh-policy', [AttendanceAdminController::class, 'updateWfhPolicy'])->name('attendance.admin.wfh-policy');
        // Position rate card (manday/manhour costing bands) — privileged; screen GET is role-gated in AppController.
        Route::post('/app/position', [PositionController::class, 'store'])->name('position.store');
        // Bulk import — register before the /{position} wildcard so "import" isn't read as an id.
        Route::post('/app/position/import', [PositionController::class, 'import'])->name('position.import');
        Route::post('/app/position/{position}', [PositionController::class, 'update'])->name('position.update');
        Route::post('/app/position/{position}/delete', [PositionController::class, 'destroy'])->name('position.destroy');
        Route::post('/app/position/assign/{employee}', [PositionController::class, 'assign'])->name('position.assign');
        Route::post('/app/claims', [ClaimController::class, 'store'])->name('claims.store');
        Route::post('/app/claims/{claim}/verify', [ClaimController::class, 'verify'])->name('claims.verify');
        Route::post('/app/claims/{claim}/approve', [ClaimController::class, 'approve'])->name('claims.approve');
        Route::post('/app/claims/{claim}/reject', [ClaimController::class, 'reject'])->name('claims.reject');
        Route::get('/app/claims/{claim}/receipt', [ClaimController::class, 'receipt'])->name('claims.receipt');
        Route::post('/app/handbook/{section}/acknowledge', [HandbookController::class, 'acknowledge'])->name('handbook.acknowledge');
        Route::post('/app/achievements', [AchievementController::class, 'store'])->name('achievements.store');
        Route::post('/app/reviews/{review}/acknowledge', [ReviewController::class, 'acknowledge'])->name('reviews.acknowledge');
        Route::post('/app/reviews/{review}/self-assessment', [ReviewController::class, 'selfAssessment'])->name('reviews.self');
        Route::post('/app/reviews/{review}/complete', [ReviewController::class, 'complete'])->name('reviews.complete');
        Route::post('/app/reviews/{review}/rate', [ReviewController::class, 'rate'])->name('reviews.rate');
        Route::post('/app/board', [WorkItemController::class, 'store'])->name('work.store');
        Route::post('/app/board/assign/{employee}', [WorkItemController::class, 'assign'])->name('work.assign');
        Route::get('/app/board/{workItem}', [WorkItemController::class, 'show'])->name('work.show');
        Route::post('/app/board/{workItem}/move', [WorkItemController::class, 'move'])->name('work.move');
        Route::patch('/app/board/{workItem}', [WorkItemController::class, 'update'])->name('work.update');
        Route::delete('/app/board/{workItem}', [WorkItemController::class, 'destroy'])->name('work.destroy');
        // AI Workforce Intelligence — "Apply" a recommendation as an in-app nudge (privileged only).
        Route::post('/app/workload/apply', [WorkforceController::class, 'apply'])->name('workforce.apply');
        Route::post('/app/board/{workItem}/comments', [WorkItemController::class, 'comment'])->name('work.comment');
        Route::delete('/app/board/comments/{comment}', [WorkItemController::class, 'commentDestroy'])->name('work.comment.destroy');
        Route::post('/app/employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::post('/app/employees/import', [EmployeeController::class, 'import'])->name('employees.import');
        Route::post('/app/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::post('/app/employees/{employee}/delete', [EmployeeController::class, 'destroy'])->name('employees.destroy');
        Route::post('/app/employees/{employee}/restore', [EmployeeController::class, 'restore'])->name('employees.restore');
        Route::post('/app/employees/{employee}/force-delete', [EmployeeController::class, 'forceDelete'])->name('employees.force-delete');
        Route::post('/app/org/reporting-lines', [OrgController::class, 'updateLines'])->name('org.reporting-lines');
        Route::post('/app/org/move', [OrgController::class, 'move'])->name('org.move');
        Route::post('/app/onboarding/tasks/{task}/toggle', [OnboardingController::class, 'toggleTask'])->name('onboarding.toggle');
        Route::post('/app/onboarding/start', [OnboardingController::class, 'start'])->name('onboarding.start');
        Route::post('/app/onboarding/{profile}/tasks', [OnboardingController::class, 'addTask'])->name('onboarding.tasks.add');
        Route::post('/app/onboarding/tasks/{task}/remove', [OnboardingController::class, 'removeTask'])->name('onboarding.tasks.remove');
        // Company onboarding content library (privileged): text/video/file/ack per checklist item.
        Route::post('/app/onboarding/content', [OnboardingContentController::class, 'save'])->middleware('throttle:30,1')->name('onboarding.content.save');
        Route::get('/app/onboarding/content/{resource}/file', [OnboardingContentController::class, 'download'])->name('onboarding.content.file');
        Route::post('/app/kpi/{kpiItem}', [KpiController::class, 'update'])->name('kpi.update');
        Route::post('/app/training', [TrainingController::class, 'store'])->name('training.store');
        Route::post('/app/training/{training}/complete', [TrainingController::class, 'complete'])->name('training.complete');
        Route::post('/app/assets', [AssetController::class, 'store'])->name('assets.store');
        Route::post('/app/assets/{asset}/assign', [AssetController::class, 'assign'])->name('assets.assign');
        Route::post('/app/assets/{asset}/release', [AssetController::class, 'release'])->name('assets.release');
        Route::post('/app/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
        // Company onboarding wizard (privileged) — ordered shell over existing CRUD.
        Route::post('/app/setup/step', [SetupController::class, 'markStep'])->name('setup.step');
        Route::post('/app/setup/finish', [SetupController::class, 'finish'])->name('setup.finish');
        // Staff first-login wizard (self-service). Reachable while profile.complete gates
        // the rest of the shell; each step also doubles as the self-edit path for that data.
        Route::get('/app/welcome', [WelcomeWizardController::class, 'show'])->name('welcome.show');
        Route::post('/app/welcome/personal', [WelcomeWizardController::class, 'savePersonal'])->name('welcome.personal');
        Route::post('/app/welcome/bank', [WelcomeWizardController::class, 'saveBank'])->name('welcome.bank');
        Route::post('/app/welcome/certificate', [WelcomeWizardController::class, 'uploadCertificate'])->middleware('throttle:20,1')->name('welcome.certificate');
        Route::post('/app/welcome/finish', [WelcomeWizardController::class, 'finish'])->name('welcome.finish');
        Route::post('/app/admin/settings', [AdminController::class, 'updateSettings'])->name('admin.settings.update');
        Route::post('/app/admin/features', [AdminController::class, 'updateFeatures'])->name('admin.features.update');
        Route::post('/app/admin/roles/{user}', [AdminController::class, 'updateRole'])->name('admin.roles.update');
        Route::post('/app/admin/scope/{user}', [AdminController::class, 'updateScope'])->name('admin.scope.update');
        Route::post('/app/admin/permissions/{user}', [AdminController::class, 'updatePermissions'])->name('admin.permissions.update');
        // Org structure (branches + departments) — name/state CRUD; geofence stays on Attendance Setup.
        Route::post('/app/admin/branches', [AdminController::class, 'storeBranch'])->name('admin.branches.store');
        Route::post('/app/admin/branches/{branch}', [AdminController::class, 'updateBranch'])->name('admin.branches.update');
        Route::post('/app/admin/branches/{branch}/delete', [AdminController::class, 'deleteBranch'])->name('admin.branches.delete');
        Route::post('/app/admin/departments', [AdminController::class, 'storeDepartment'])->name('admin.departments.store');
        Route::post('/app/admin/departments/{department}', [AdminController::class, 'updateDepartment'])->name('admin.departments.update');
        Route::post('/app/admin/departments/{department}/delete', [AdminController::class, 'deleteDepartment'])->name('admin.departments.delete');
        // Staff levels (grades) + employment types — tenant lookups used on staff records.
        Route::post('/app/admin/staff-levels', [AdminController::class, 'storeStaffLevel'])->name('admin.staff-levels.store');
        Route::post('/app/admin/staff-levels/{staffLevel}', [AdminController::class, 'updateStaffLevel'])->name('admin.staff-levels.update');
        Route::post('/app/admin/staff-levels/{staffLevel}/delete', [AdminController::class, 'deleteStaffLevel'])->name('admin.staff-levels.delete');
        Route::post('/app/admin/employment-types', [AdminController::class, 'storeEmploymentType'])->name('admin.employment-types.store');
        Route::post('/app/admin/employment-types/{employmentType}', [AdminController::class, 'updateEmploymentType'])->name('admin.employment-types.update');
        Route::post('/app/admin/employment-types/{employmentType}/delete', [AdminController::class, 'deleteEmploymentType'])->name('admin.employment-types.delete');
        Route::post('/app/members', [MemberController::class, 'store'])->name('members.store');
        Route::post('/app/members/provision', [MemberController::class, 'provisionLogins'])->name('members.provision');
        Route::post('/app/members/{employee}/login', [MemberController::class, 'createLogin'])->name('members.create-login');
        Route::post('/app/members/{employee}/reset-password', [MemberController::class, 'resetPassword'])->name('members.reset-password');
        Route::post('/app/security/two-factor/disable', [SecurityController::class, 'disableTwoFactor'])->name('security.2fa.disable');
        Route::post('/app/assistant', [AssistantController::class, 'reply'])->middleware('throttle:20,1')->name('assistant.reply');
        Route::post('/app/notifications/read', [NotificationController::class, 'markRead'])->name('notifications.read');
        // Roster (shift scheduling)
        Route::post('/app/roster', [RosterController::class, 'store'])->name('roster.store');
        Route::post('/app/roster/{shift}/cancel', [RosterController::class, 'cancel'])->name('roster.cancel');
        // Document vault
        Route::post('/app/documents', [DocumentController::class, 'store'])->name('documents.store');
        Route::post('/app/documents/{document}/delete', [DocumentController::class, 'destroy'])->name('documents.destroy');
        // Pulse surveys
        Route::post('/app/surveys', [SurveyController::class, 'store'])->name('surveys.store');
        Route::post('/app/surveys/{survey}/respond', [SurveyController::class, 'respond'])->name('surveys.respond');
        Route::post('/app/surveys/{survey}/close', [SurveyController::class, 'close'])->name('surveys.close');
        // Helpdesk / IT tickets
        Route::post('/app/helpdesk', [HelpdeskController::class, 'store'])->name('helpdesk.store');
        Route::post('/app/helpdesk/{ticket}', [HelpdeskController::class, 'update'])->name('helpdesk.update');
        // Shared company resources (Gmail, Canva, WhatsApp, inhouse system, etc.) —
        // all staff view via the screen; privileged roles (manager/management/hr) maintain.
        Route::post('/app/shared-resources', [SharedResourceController::class, 'store'])->name('shared-resources.store');
        Route::post('/app/shared-resources/{resource}', [SharedResourceController::class, 'update'])->name('shared-resources.update');
        Route::post('/app/shared-resources/{resource}/delete', [SharedResourceController::class, 'destroy'])->name('shared-resources.destroy');
        // Company events
        Route::post('/app/events', [EventController::class, 'store'])->name('events.store');
        Route::post('/app/events/{event}/rsvp', [EventController::class, 'rsvp'])->name('events.rsvp');
        // Offboarding / exit clearance
        Route::post('/app/offboarding', [OffboardingController::class, 'store'])->name('offboarding.store');
        Route::post('/app/offboarding/items/{item}/toggle', [OffboardingController::class, 'toggleItem'])->name('offboarding.toggle');
        Route::post('/app/offboarding/{case}/items', [OffboardingController::class, 'addItem'])->name('offboarding.items.add');
        Route::post('/app/offboarding/items/{item}/remove', [OffboardingController::class, 'removeItem'])->name('offboarding.items.remove');
        // Goals / OKRs
        Route::post('/app/goals', [GoalController::class, 'store'])->name('goals.store');
        Route::post('/app/goals/{goal}/key-results', [GoalController::class, 'addKeyResult'])->name('goals.kr.store');
        Route::post('/app/goals/key-results/{keyResult}/progress', [GoalController::class, 'updateProgress'])->name('goals.kr.progress');
        // Recruitment / ATS
        Route::post('/app/recruitment/requisitions', [RecruitmentController::class, 'storeRequisition'])->name('recruitment.requisitions');
        Route::post('/app/recruitment/{requisition}/candidates', [RecruitmentController::class, 'storeCandidate'])->name('recruitment.candidates');
        Route::post('/app/recruitment/candidates/{candidate}/move', [RecruitmentController::class, 'moveCandidate'])->name('recruitment.move');
        // Loans & advances
        Route::post('/app/loans', [LoanController::class, 'store'])->middleware('throttle:20,1')->name('loans.store');
        Route::post('/app/loans/{loan}/approve', [LoanController::class, 'approve'])->name('loans.approve');
        Route::post('/app/loans/{loan}/reject', [LoanController::class, 'reject'])->name('loans.reject');
        // Travel & business trips
        Route::post('/app/travel', [TravelController::class, 'store'])->middleware('throttle:20,1')->name('travel.store');
        Route::post('/app/travel/{travel}/approve', [TravelController::class, 'approve'])->name('travel.approve');
        Route::post('/app/travel/{travel}/reject', [TravelController::class, 'reject'])->name('travel.reject');
        // Meeting room booking
        Route::post('/app/rooms/book', [RoomController::class, 'store'])->name('rooms.book');
        Route::post('/app/rooms/bookings/{booking}/cancel', [RoomController::class, 'cancel'])->name('rooms.cancel');
        Route::post('/app/rooms', [RoomController::class, 'storeRoom'])->name('rooms.store');
        // Disciplinary & grievance cases (confidential, privileged-only)
        Route::post('/app/cases', [CaseController::class, 'store'])->name('cases.store');
        Route::post('/app/cases/{case}', [CaseController::class, 'update'])->name('cases.update');
        // Feedback hub (report a bug / suggest an idea) — pinned in the sidebar, opens a modal.
        Route::post('/app/feedback', [FeedbackController::class, 'store'])->middleware('throttle:20,1')->name('feedback.store');
        // Feedback inbox triage — management/HR move an item along its lifecycle.
        Route::post('/app/feedback/{feedback}/status', [FeedbackController::class, 'setStatus'])->name('feedback.status');
        // Stream a report's screenshot/document — auth-gated (reporter or inbox viewer), never public.
        Route::get('/app/feedback/attachments/{attachment}', [FeedbackController::class, 'attachment'])->name('feedback.attachment');
        // Profile test (self-service personality instrument) + HR question editor
        Route::post('/app/profile-test', [ProfileTestController::class, 'submit'])->name('profile-test.submit');
        Route::post('/app/profile-test/questions', [ProfileTestController::class, 'storeQuestion'])->name('profile-test.questions.store');
        Route::post('/app/profile-test/questions/{question}', [ProfileTestController::class, 'updateQuestion'])->name('profile-test.questions.update');
        Route::post('/app/profile-test/questions/{question}/delete', [ProfileTestController::class, 'destroyQuestion'])->name('profile-test.questions.destroy');
        // Suggestion box / ideas
        Route::post('/app/ideas', [IdeaController::class, 'store'])->name('ideas.store');
        Route::post('/app/ideas/{idea}/vote', [IdeaController::class, 'vote'])->name('ideas.vote');
        Route::post('/app/ideas/{idea}/status', [IdeaController::class, 'setStatus'])->name('ideas.status');
        // Knowledge Bank — company lesson sharing. Paths share the `knowledge-bank`
        // first segment so EnsureModuleEnabled gates them under module.knowledge.
        Route::post('/app/knowledge-bank', [KnowledgeController::class, 'store'])->name('knowledge.store');
        Route::post('/app/knowledge-bank/segments', [KnowledgeController::class, 'storeSegment'])->name('knowledge.segments');
        Route::post('/app/knowledge-bank/read-all', [KnowledgeController::class, 'markAllRead'])->name('knowledge.read');
        Route::post('/app/knowledge-bank/{entry}/star', [KnowledgeController::class, 'toggleStar'])->name('knowledge.star');
        Route::post('/app/knowledge-bank/{entry}/comments', [KnowledgeController::class, 'comment'])->name('knowledge.comments');
        Route::delete('/app/knowledge-bank/comments/{comment}', [KnowledgeController::class, 'deleteComment'])->name('knowledge.comments.delete');
        // Direct messaging — 1-to-1 threads. Paths share the `messages` first segment so
        // EnsureModuleEnabled gates them under module.messages.
        Route::post('/app/messages/send', [MessageController::class, 'send'])->middleware('throttle:60,1')->name('messages.send');
        Route::post('/app/messages/{conversation}/read', [MessageController::class, 'markRead'])->name('messages.read');
        // Benefits & insurance enrollment
        Route::post('/app/benefits/{plan}/enroll', [BenefitController::class, 'enroll'])->name('benefits.enroll');
        Route::post('/app/benefits/plans', [BenefitController::class, 'storePlan'])->name('benefits.plans');
        // Expense reports (multi-line, with approval)
        Route::post('/app/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::post('/app/expenses/{report}/lines', [ExpenseController::class, 'addLine'])->name('expenses.lines');
        Route::post('/app/expenses/{report}/submit', [ExpenseController::class, 'submit'])->name('expenses.submit');
        Route::post('/app/expenses/{report}/approve', [ExpenseController::class, 'approve'])->name('expenses.approve');
        Route::post('/app/expenses/{report}/reject', [ExpenseController::class, 'reject'])->name('expenses.reject');
        // Probation tracking (privileged — manager and above)
        Route::post('/app/probation', [ProbationController::class, 'store'])->name('probation.store');
        Route::post('/app/probation/{review}/checkin', [ProbationController::class, 'checkin'])->name('probation.checkin');
        Route::post('/app/probation/{review}/decide', [ProbationController::class, 'decide'])->name('probation.decide');
        // Overtime requests
        Route::post('/app/overtime', [OvertimeController::class, 'store'])->middleware('throttle:20,1')->name('overtime.store');
        Route::post('/app/overtime/{overtime}/verify', [OvertimeController::class, 'verify'])->name('overtime.verify');
        Route::post('/app/overtime/{overtime}/approve', [OvertimeController::class, 'approve'])->name('overtime.approve');
        Route::post('/app/overtime/{overtime}/reject', [OvertimeController::class, 'reject'])->name('overtime.reject');
        // Resignation & exit interview
        Route::post('/app/resignation', [ResignationController::class, 'store'])->name('resignation.store');
        Route::post('/app/resignation/{resignation}/acknowledge', [ResignationController::class, 'acknowledge'])->name('resignation.acknowledge');
        Route::post('/app/resignation/{resignation}/withdraw', [ResignationController::class, 'withdraw'])->name('resignation.withdraw');
        Route::post('/app/resignation/{resignation}/interview', [ResignationController::class, 'interview'])->name('resignation.interview');
        // Compliance & license tracking (privileged manage)
        Route::post('/app/compliance', [ComplianceController::class, 'store'])->name('compliance.store');
        Route::post('/app/compliance/{item}/renew', [ComplianceController::class, 'renew'])->name('compliance.renew');
        Route::delete('/app/compliance/{item}', [ComplianceController::class, 'destroy'])->name('compliance.destroy');
        // Timesheets (weekly hours, parent + entries; staff self-finalise, no approval)
        Route::post('/app/timesheets', [TimesheetController::class, 'store'])->name('timesheets.store');
        Route::post('/app/timesheets/{timesheet}/submit', [TimesheetController::class, 'submit'])->name('timesheets.submit');
        Route::post('/app/timesheets/{timesheet}/recall', [TimesheetController::class, 'recall'])->name('timesheets.recall');
        // Per-staff reusable allocation templates (owned by the acting employee)
        Route::post('/app/timesheets/templates', [TimesheetController::class, 'storeTemplate'])->name('timesheets.templates.store');
        Route::delete('/app/timesheets/templates/{template}', [TimesheetController::class, 'deleteTemplate'])->name('timesheets.templates.delete');
        // Timesheet master data (categories / projects / sub-pillars) — privileged (management / HR)
        Route::post('/app/timesheet-setup/categories', [TimesheetAdminController::class, 'storeCategory'])->name('timesheet.admin.categories.store');
        Route::post('/app/timesheet-setup/categories/{category}', [TimesheetAdminController::class, 'updateCategory'])->name('timesheet.admin.categories.update');
        Route::post('/app/timesheet-setup/categories/{category}/delete', [TimesheetAdminController::class, 'deleteCategory'])->name('timesheet.admin.categories.delete');
        Route::post('/app/timesheet-setup/projects', [TimesheetAdminController::class, 'storeProject'])->name('timesheet.admin.projects.store');
        Route::post('/app/timesheet-setup/projects/{project}', [TimesheetAdminController::class, 'updateProject'])->name('timesheet.admin.projects.update');
        Route::post('/app/timesheet-setup/projects/{project}/delete', [TimesheetAdminController::class, 'deleteProject'])->name('timesheet.admin.projects.delete');
        Route::post('/app/timesheet-setup/projects/{project}/subpillars', [TimesheetAdminController::class, 'storeSubPillar'])->name('timesheet.admin.subpillars.store');
        Route::post('/app/timesheet-setup/subpillars/{subPillar}', [TimesheetAdminController::class, 'updateSubPillar'])->name('timesheet.admin.subpillars.update');
        Route::post('/app/timesheet-setup/subpillars/{subPillar}/delete', [TimesheetAdminController::class, 'deleteSubPillar'])->name('timesheet.admin.subpillars.delete');
        // Learning library / LMS
        Route::post('/app/learning/courses', [LearningController::class, 'storeCourse'])->name('learning.courses');
        Route::post('/app/learning/{course}/enroll', [LearningController::class, 'enroll'])->name('learning.enroll');
        Route::post('/app/learning/{course}/progress', [LearningController::class, 'updateProgress'])->name('learning.progress');
        Route::post('/app/learning/{course}/complete', [LearningController::class, 'complete'])->name('learning.complete');
        // Skills matrix
        Route::post('/app/skills/catalog', [SkillController::class, 'storeSkill'])->name('skills.catalog');
        Route::post('/app/skills/rate', [SkillController::class, 'rate'])->name('skills.rate');
        Route::post('/app/skills/verify/{employeeSkill}', [SkillController::class, 'verify'])->name('skills.verify');
        // Employee referrals
        Route::post('/app/referrals', [ReferralController::class, 'store'])->middleware('throttle:20,1')->name('referrals.store');
        Route::post('/app/referrals/{referral}/status', [ReferralController::class, 'setStatus'])->name('referrals.status');
        // Shift swaps / cover (extends Roster)
        Route::post('/app/shiftswap', [ShiftSwapController::class, 'store'])->name('shiftswap.store');
        Route::post('/app/shiftswap/{swap}/accept', [ShiftSwapController::class, 'accept'])->name('shiftswap.accept');
        Route::post('/app/shiftswap/{swap}/approve', [ShiftSwapController::class, 'approve'])->name('shiftswap.approve');
        Route::post('/app/shiftswap/{swap}/reject', [ShiftSwapController::class, 'reject'])->name('shiftswap.reject');
        // Petty cash / float (privileged manage)
        Route::post('/app/pettycash', [PettyCashController::class, 'storeFloat'])->name('pettycash.floats');
        Route::post('/app/pettycash/{float}/disburse', [PettyCashController::class, 'disburse'])->name('pettycash.disburse');
        Route::post('/app/pettycash/{float}/replenish', [PettyCashController::class, 'replenish'])->name('pettycash.replenish');
        Route::post('/app/pettycash/{float}/delete', [PettyCashController::class, 'destroyFloat'])->name('pettycash.delete');
        // Vehicle / fleet booking (overlap-checked)
        Route::post('/app/vehicles/book', [VehicleController::class, 'store'])->name('vehicles.book');
        Route::post('/app/vehicles/bookings/{booking}/cancel', [VehicleController::class, 'cancel'])->name('vehicles.cancel');
        Route::post('/app/vehicles', [VehicleController::class, 'storeVehicle'])->name('vehicles.store');
        // Wellness / EAP (confidential)
        Route::post('/app/wellness/checkin', [WellnessController::class, 'checkin'])->name('wellness.checkin');
        Route::post('/app/wellness/request', [WellnessController::class, 'requestSession'])->name('wellness.request');
        Route::post('/app/wellness/requests/{req}', [WellnessController::class, 'resolveRequest'])->name('wellness.resolve');
        Route::post('/app/wellness/resources', [WellnessController::class, 'storeResource'])->name('wellness.resources');
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/app/payroll/salary', [PayrollController::class, 'storeSalary'])->name('payroll.salary');
            Route::post('/app/payroll/rates', [PayrollController::class, 'updateRates'])->name('payroll.rates');
            Route::post('/app/payroll/runs', [PayrollController::class, 'createRun'])->name('payroll.runs.create');
            Route::post('/app/payroll/runs/{run}/approve', [PayrollController::class, 'approveRun'])->name('payroll.runs.approve');
            Route::post('/app/payroll/runs/{run}/finalize', [PayrollController::class, 'finalizeRun'])->name('payroll.runs.finalize');
            Route::post('/app/payroll/payslips/{payslip}', [PayrollController::class, 'updatePayslip'])->name('payroll.payslips.update');
        });

        // GET endpoints — before the catch-all so they aren't swallowed by /app/{screen?}.
        Route::get('/app/search', [SearchController::class, 'index'])->middleware('throttle:60,1')->name('search.index');
        // Messaging JSON — the ~30s unread poll + the slide-over's inline thread load.
        // Must sit above the /app/{screen?} catch-all or they resolve as screen names.
        Route::get('/app/messages/unread', [MessageController::class, 'unread'])->middleware('throttle:120,1')->name('messages.unread');
        Route::get('/app/messages/thread/{conversation}', [MessageController::class, 'thread'])->name('messages.thread');
        Route::get('/app/messages/attachments/{attachment}', [MessageController::class, 'attachment'])->name('messages.attachment');
        Route::get('/app/employees/import-template', [EmployeeController::class, 'importTemplate'])->name('employees.import.template');
        Route::get('/app/position/import-template', [PositionController::class, 'importTemplate'])->name('position.import.template');
        Route::get('/app/reports/export/employees', [ReportController::class, 'exportEmployees'])->name('reports.export.employees');
        Route::get('/app/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
        Route::get('/app/payroll/runs/{run}/bank-file', [PayrollExportController::class, 'bankFile'])->name('payroll.export.bank');
        Route::get('/app/payroll/runs/{run}/statutory-report', [PayrollExportController::class, 'statutoryReport'])->name('payroll.export.statutory');

        // App shell — all screens render through one controller action. Two gates run
        // only here (the staff navigation funnel), never on write-paths: system.launched
        // holds plain staff on a "being set up" screen until HR finishes critical setup;
        // profile.complete funnels a staff member with an unfinished essential profile into
        // the first-login wizard. The /app/welcome routes above are separate, so the wizard
        // itself is always reachable (no redirect loop).
        Route::get('/app/{screen?}', [AppController::class, 'screen'])
            ->middleware(['system.launched', 'profile.complete'])
            ->name('app.screen');
    });
});
