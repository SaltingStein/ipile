/**
 * A 0-100 Aegis risk score with its level. The level is always written out, so severity never relies on colour alone.
 * Bands match App\Services\Security\Markov\RiskPostureChain::levelFor().
 */
interface RiskBadgeProps {
    score: number;
    showScore?: boolean;
}

export function riskLevel(score: number): 'normal' | 'elevated' | 'high' | 'critical' {
    if (score >= 85) {
        return 'critical';
    }
    if (score >= 60) {
        return 'high';
    }
    if (score >= 30) {
        return 'elevated';
    }

    return 'normal';
}

const levelStyles: Record<ReturnType<typeof riskLevel>, { label: string; icon: string; classes: string }> = {
    normal: { label: 'Normal', icon: '●', classes: 'border-slate-700 bg-slate-800/60 text-slate-300' },
    elevated: { label: 'Elevated', icon: '▲', classes: 'border-amber-700/60 bg-amber-950/40 text-amber-200' },
    high: { label: 'High', icon: '▲', classes: 'border-orange-600/60 bg-orange-950/50 text-orange-200' },
    critical: { label: 'Critical', icon: '■', classes: 'border-rose-600/70 bg-rose-950/60 text-rose-200' },
};

export default function RiskBadge({ score, showScore = true }: RiskBadgeProps) {
    const style = levelStyles[riskLevel(score)];

    return (
        <span className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2 py-0.5 text-xs font-medium ${style.classes}`}>
            <span aria-hidden="true" className="text-[0.6rem]">{style.icon}</span>
            {style.label}
            {showScore && <span className="tabular-nums">{score}</span>}
        </span>
    );
}
