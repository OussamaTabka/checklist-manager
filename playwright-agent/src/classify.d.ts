import { z } from 'zod';
declare const ScenarioSchema: z.ZodObject<{
    scenario: z.ZodEnum<{
        dashboard: "dashboard";
        checklists: "checklists";
        projects: "projects";
        users: "users";
        login: "login";
        unknown: "unknown";
    }>;
    confidence: z.ZodOptional<z.ZodNumber>;
}, z.core.$strip>;
export type Scenario = z.infer<typeof ScenarioSchema>['scenario'];
export declare function classifyItem(opts: {
    baseUrl: string;
    model: string;
    timeoutMs?: number;
    title: string;
    description?: string;
    context?: Record<string, unknown>;
}): Promise<{
    scenario: Scenario;
    confidence?: number;
}>;
export {};
//# sourceMappingURL=classify.d.ts.map