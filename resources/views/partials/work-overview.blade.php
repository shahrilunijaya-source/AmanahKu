{{--
    The overview beside the card drawer: the top-level card and its subtasks. Lives
    INSIDE .wd-scrim so a click on the dimmed area still closes the drawer, while the
    panel itself stops that click. Shown only when there is a family to show: a parent
    with at least one child, or a child (whose parent is then the top card).

    Read-only on the team board ($interactive = false): no add row, no ticks.
--}}
<div class="wd-ov" x-show="drawer.family && (drawer.family.children.length || drawer.card.parent_id)" x-cloak @click.stop>
    <p class="wd-ov-h" x-text="$store.ui.lang==='en' ? 'Parent' : 'Induk'"></p>
    <button type="button" class="wd-ov-parent" :class="{ 'is-active': drawer.family && String(drawer.card.id) === String(drawer.family.parent.id) }" @click="backToParent()">
        <span class="wd-ov-type" x-text="drawer.family?.parent.type"></span>
        <span class="wd-ov-title" x-text="drawer.family?.parent.title"></span>
        <span class="wd-ov-meta">
            <span x-text="drawer.family?.parent.due_label || ($store.ui.lang==='en' ? 'No due date' : 'Tiada tarikh akhir')"></span>
            <span x-show="drawer.family?.parent.child_summary" x-text="drawer.family?.parent.child_summary ? drawer.family.parent.child_summary.done + '/' + drawer.family.parent.child_summary.total : ''"></span>
        </span>
    </button>

    <p class="wd-ov-h">
        <span x-text="$store.ui.lang==='en' ? 'Subtasks' : 'Subtugas'"></span>
        <span x-show="drawer.family?.parent.child_summary" x-text="' · ' + (drawer.family?.parent.child_summary?.done ?? 0) + ' ' + ($store.ui.lang==='en' ? 'of' : 'daripada') + ' ' + (drawer.family?.parent.child_summary?.total ?? 0) + ' ' + ($store.ui.lang==='en' ? 'done' : 'siap')"></span>
    </p>
    <template x-for="child in (drawer.family ? drawer.family.children : [])" :key="child.id">
        <div class="wd-ov-child" :class="{ 'is-active': String(drawer.card.id) === String(child.id), 'is-done': child.status === 'done' }" @click.self="openChild(child.id)">
            @if ($interactive)
                <input type="checkbox" :checked="child.status === 'done'" @change="tickChild(child)"
                       :aria-label="$store.ui.lang==='en' ? 'Mark done' : 'Tanda siap'">
            @else
                <input type="checkbox" :checked="child.status === 'done'" disabled>
            @endif
            <button type="button" class="wd-ov-child-title" @click="openChild(child.id)"><span x-text="child.title"></span></button>
            <span class="wd-ov-meta">
                <template x-for="p in child.people.slice(0, 2)" :key="p.initials + p.name">
                    <span class="wa" :style="'background:' + p.color" :title="p.name" x-text="p.initials"></span>
                </template>
                <span x-show="!child.people.length" x-text="child.due_label"></span>
            </span>
        </div>
    </template>

    @if ($interactive)
        <div class="wd-ov-add" x-show="drawer.canAddChild">
            <input class="wd-inline" x-model="drawer.newChildTitle" maxlength="160" :disabled="drawer.addingChild"
                   :placeholder="$store.ui.lang==='en' ? '+ Add a subtask' : '+ Tambah subtugas'"
                   @keydown.enter.prevent="addChild()">
        </div>
    @endif
</div>
