import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import StatCard from '@/Components/StatCard.vue'

describe('StatCard', () => {
    it('renders the value', () => {
        const wrapper = mount(StatCard, {
            props: { value: 42, label: 'Ideas' },
        })
        expect(wrapper.text()).toContain('42')
    })

    it('renders the label', () => {
        const wrapper = mount(StatCard, {
            props: { value: 10, label: 'Total Scans' },
        })
        expect(wrapper.text()).toContain('Total Scans')
    })

    it('shows em dash when value is null', () => {
        const wrapper = mount(StatCard, {
            props: { value: null, label: 'Score' },
        })
        expect(wrapper.text()).toContain('—')
    })

    it('shows em dash when value is undefined', () => {
        const wrapper = mount(StatCard, {
            props: { value: undefined, label: 'Score' },
        })
        expect(wrapper.text()).toContain('—')
    })

    it('shows 0 when value is 0 (not treated as falsy)', () => {
        const wrapper = mount(StatCard, {
            props: { value: 0, label: 'Ideas' },
        })
        expect(wrapper.text()).toContain('0')
        expect(wrapper.text()).not.toContain('—')
    })

    it('renders string values', () => {
        const wrapper = mount(StatCard, {
            props: { value: '7.5', label: 'Avg Score' },
        })
        expect(wrapper.text()).toContain('7.5')
    })

    it('applies brand color when highlight=true', () => {
        const wrapper = mount(StatCard, {
            props: { value: 99, label: 'Ideas', highlight: true },
        })
        const valueSpan = wrapper.find('span')
        expect(valueSpan.classes()).toContain('text-brand-500')
    })

    it('applies primary color when highlight=false (default)', () => {
        const wrapper = mount(StatCard, {
            props: { value: 99, label: 'Ideas' },
        })
        const valueSpan = wrapper.find('span')
        expect(valueSpan.classes()).toContain('text-content-primary')
        expect(valueSpan.classes()).not.toContain('text-brand-500')
    })
})
