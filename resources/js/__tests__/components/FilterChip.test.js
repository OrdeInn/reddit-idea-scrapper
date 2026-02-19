import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FilterChip from '@/Components/FilterChip.vue'

describe('FilterChip', () => {
    it('renders the label text', () => {
        const wrapper = mount(FilterChip, {
            props: { label: 'Starred Only' },
        })
        expect(wrapper.text()).toContain('Starred Only')
    })

    it('emits toggle event when clicked', async () => {
        const wrapper = mount(FilterChip, {
            props: { label: 'Filter' },
        })

        await wrapper.find('button').trigger('click')

        expect(wrapper.emitted('toggle')).toHaveLength(1)
    })

    it('sets aria-pressed=true when active', () => {
        const wrapper = mount(FilterChip, {
            props: { label: 'Active Filter', active: true },
        })
        expect(wrapper.find('button').attributes('aria-pressed')).toBe('true')
    })

    it('sets aria-pressed=false when not active', () => {
        const wrapper = mount(FilterChip, {
            props: { label: 'Inactive Filter', active: false },
        })
        expect(wrapper.find('button').attributes('aria-pressed')).toBe('false')
    })

    it('shows count badge when count prop is provided', () => {
        const wrapper = mount(FilterChip, {
            props: { label: 'Items', count: 12 },
        })
        expect(wrapper.text()).toContain('12')
        // Count badge should have aria-label
        expect(wrapper.find('[aria-label]').exists()).toBe(true)
    })

    it('does not show count badge when count is null', () => {
        const wrapper = mount(FilterChip, {
            props: { label: 'Items', count: null },
        })
        // Only label text, no count badge
        expect(wrapper.findAll('span')).toHaveLength(1)
    })

    it('shows count 0 when count is 0', () => {
        const wrapper = mount(FilterChip, {
            props: { label: 'Items', count: 0 },
        })
        expect(wrapper.text()).toContain('0')
    })

    it('applies active styling classes when active=true', () => {
        const wrapper = mount(FilterChip, {
            props: { label: 'Filter', active: true },
        })
        const btn = wrapper.find('button')
        expect(btn.classes()).toContain('bg-brand-100')
        expect(btn.classes()).toContain('text-brand-700')
    })

    it('applies inactive styling classes when active=false', () => {
        const wrapper = mount(FilterChip, {
            props: { label: 'Filter', active: false },
        })
        const btn = wrapper.find('button')
        expect(btn.classes()).toContain('bg-surface-tertiary')
        expect(btn.classes()).toContain('text-content-secondary')
    })
})
