import { test, expect } from 'bun:test';
import { planBalance } from './dashboard-widgets';

test('moves the tail of the long column onto the short one', () => {
    // 4 short cards beside 5 tall ones: the blank half the balancer exists for.
    expect(planBalance([200, 200, 200, 200], [400, 400, 400, 400, 400])).toEqual({ from: 'right', count: 1 });
});

test('leaves two already-even columns alone', () => {
    expect(planBalance([300, 300], [300, 300])).toBeNull();
});

test('stops before a move that would overshoot', () => {
    // Moving the 500 swaps a 100px gap for a 936px one, so it does not happen.
    expect(planBalance([600], [600, 500])).toBeNull();
});

test('never empties a column', () => {
    // Three tall cards facing one small one: even after the move the columns are
    // uneven, but taking a second would swing the gap the other way.
    expect(planBalance([50], [900, 900, 900])).toEqual({ from: 'right', count: 1 });
});

test('only ever takes from one side', () => {
    const plan = planBalance([1000, 20], [30]);
    expect(plan.from).toBe('left');
    expect(plan.count).toBe(1);
});

test('an empty dashboard plans nothing', () => {
    expect(planBalance([], [])).toBeNull();
});
