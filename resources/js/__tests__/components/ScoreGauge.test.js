import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ScoreGauge from '@/Components/ScoreGauge.vue'

describe('ScoreGauge', () => {
    it('renders an SVG element', () => {
        const wrapper = mount(ScoreGauge, {
            props: { score: 7, animate: false },
        })
        expect(wrapper.find('svg').exists()).toBe(true)
    })

    it('has correct aria-label with score value', () => {
        const wrapper = mount(ScoreGauge, {
            props: { score: 4, animate: false },
        })
        expect(wrapper.find('svg').attributes('aria-label')).toBe('Score: 4 out of 5')
    })

    it('has aria-label indicating not rated when score is 0', () => {
        const wrapper = mount(ScoreGauge, {
            props: { score: 0, animate: false },
        })
        expect(wrapper.find('svg').attributes('aria-label')).toBe('Score: not rated')
    })

    it('shows em dash for zero score', () => {
        const wrapper = mount(ScoreGauge, {
            props: { score: 0, animate: false },
        })
        expect(wrapper.find('text').text()).toBe('—')
    })

    it('clamps score display to max 5', () => {
        const wrapper = mount(ScoreGauge, {
            props: { score: 9, animate: false },
        })
        // score 9 is clamped to 5
        expect(wrapper.find('text').text()).toBe('5')
    })

    it('clamps score display to min 0 for negative scores', () => {
        const wrapper = mount(ScoreGauge, {
            props: { score: -3, animate: false },
        })
        // score -3 clamps to 0; isEmpty checks props.score === 0, not clamped value
        // so -3 is NOT considered empty and shows the clamped value "0"
        expect(wrapper.find('text').text()).toBe('0')
    })

    it('applies excellent color class for score >= 4', () => {
        const wrapper = mount(ScoreGauge, {
            props: { score: 4, animate: false },
        })
        const progressCircle = wrapper.findAll('circle')[1]
        expect(progressCircle.classes()).toContain('stroke-score-excellent')
    })

    it('applies good color class for score >= 3 and < 4', () => {
        const wrapper = mount(ScoreGauge, {
            props: { score: 3, animate: false },
        })
        const progressCircle = wrapper.findAll('circle')[1]
        expect(progressCircle.classes()).toContain('stroke-score-good')
    })

    it('applies average color class for score >= 2 and < 3', () => {
        const wrapper = mount(ScoreGauge, {
            props: { score: 2, animate: false },
        })
        const progressCircle = wrapper.findAll('circle')[1]
        expect(progressCircle.classes()).toContain('stroke-score-average')
    })

    it('applies poor color class for score < 2', () => {
        const wrapper = mount(ScoreGauge, {
            props: { score: 1, animate: false },
        })
        const progressCircle = wrapper.findAll('circle')[1]
        expect(progressCircle.classes()).toContain('stroke-score-poor')
    })

    it('does not render progress circle when score is 0', () => {
        const wrapper = mount(ScoreGauge, {
            props: { score: 0, animate: false },
        })
        // Only the track circle exists, no fill circle
        expect(wrapper.findAll('circle')).toHaveLength(1)
    })

    it('shows label when provided and size >= 24', () => {
        const wrapper = mount(ScoreGauge, {
            props: { score: 5, label: 'Overall', size: 36, animate: false },
        })
        expect(wrapper.find('span').exists()).toBe(true)
        expect(wrapper.find('span').text()).toBe('Overall')
    })

    it('does not show label when size < 24', () => {
        const wrapper = mount(ScoreGauge, {
            props: { score: 5, label: 'Overall', size: 20, animate: false },
        })
        expect(wrapper.find('span').exists()).toBe(false)
    })

    it('does not show label when label is empty string', () => {
        const wrapper = mount(ScoreGauge, {
            props: { score: 5, label: '', size: 36, animate: false },
        })
        expect(wrapper.find('span').exists()).toBe(false)
    })

    it('uses default size of 36', () => {
        const wrapper = mount(ScoreGauge, {
            props: { score: 3, animate: false },
        })
        const svg = wrapper.find('svg')
        expect(svg.attributes('width')).toBe('36')
        expect(svg.attributes('height')).toBe('36')
    })
})
