import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import BaseButton from '@/Components/BaseButton.vue'

describe('BaseButton', () => {
    it('renders slot content', () => {
        const wrapper = mount(BaseButton, {
            slots: { default: 'Click me' },
        })
        expect(wrapper.text()).toContain('Click me')
    })

    it('renders as a <button> by default', () => {
        const wrapper = mount(BaseButton)
        expect(wrapper.element.tagName).toBe('BUTTON')
    })

    it('renders as an <a> when as="a" and href provided', () => {
        const wrapper = mount(BaseButton, {
            props: { as: 'a', href: 'https://example.com' },
        })
        expect(wrapper.element.tagName).toBe('A')
        expect(wrapper.attributes('href')).toBe('https://example.com')
    })

    it('has disabled attribute when disabled=true', () => {
        const wrapper = mount(BaseButton, {
            props: { disabled: true },
        })
        expect(wrapper.attributes('disabled')).toBeDefined()
        expect(wrapper.attributes('aria-disabled')).toBe('true')
    })

    it('has aria-disabled and aria-busy when loading=true', () => {
        const wrapper = mount(BaseButton, {
            props: { loading: true },
        })
        expect(wrapper.attributes('aria-disabled')).toBe('true')
        expect(wrapper.attributes('aria-busy')).toBe('true')
    })

    it('shows spinner when loading', () => {
        const wrapper = mount(BaseButton, {
            props: { loading: true },
        })
        expect(wrapper.find('svg').exists()).toBe(true)
        expect(wrapper.find('.animate-spin').exists()).toBe(true)
    })

    it('does not show spinner when not loading', () => {
        const wrapper = mount(BaseButton, {
            props: { loading: false },
            slots: { default: 'Submit' },
        })
        expect(wrapper.find('.animate-spin').exists()).toBe(false)
        expect(wrapper.text()).toContain('Submit')
    })

    it('applies primary variant classes by default', () => {
        const wrapper = mount(BaseButton)
        expect(wrapper.classes()).toContain('bg-brand-500')
    })

    it('applies secondary variant classes', () => {
        const wrapper = mount(BaseButton, {
            props: { variant: 'secondary' },
        })
        expect(wrapper.classes()).toContain('border')
    })

    it('applies danger variant classes', () => {
        const wrapper = mount(BaseButton, {
            props: { variant: 'danger' },
        })
        expect(wrapper.classes()).toContain('bg-status-error')
    })

    it('applies sm size classes', () => {
        const wrapper = mount(BaseButton, {
            props: { size: 'sm' },
        })
        expect(wrapper.classes()).toContain('h-8')
    })

    it('applies lg size classes', () => {
        const wrapper = mount(BaseButton, {
            props: { size: 'lg' },
        })
        expect(wrapper.classes()).toContain('h-12')
    })

    it('applies disabled styling when disabled', () => {
        const wrapper = mount(BaseButton, {
            props: { disabled: true },
        })
        expect(wrapper.classes()).toContain('opacity-50')
        expect(wrapper.classes()).toContain('cursor-not-allowed')
    })

    it('sets correct button type', () => {
        const wrapper = mount(BaseButton, {
            props: { type: 'submit' },
        })
        expect(wrapper.attributes('type')).toBe('submit')
    })
})
