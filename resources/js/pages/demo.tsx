import { Head } from '@inertiajs/react';
import { AlertCircleIcon, FileTextIcon } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    selectIsSubmitDisabled,
    useDemoStore,
} from '@/stores/demo-store';

export default function Demo() {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const selectedFile = useDemoStore((state) => state.selectedFile);
    const statusAvailable = useDemoStore((state) => state.statusAvailable);
    const statusWarning = useDemoStore((state) => state.statusWarning);
    const statusLoading = useDemoStore((state) => state.statusLoading);
    const uiState = useDemoStore((state) => state.uiState);
    const errorMessage = useDemoStore((state) => state.errorMessage);
    const result = useDemoStore((state) => state.result);
    const fetchStatus = useDemoStore((state) => state.fetchStatus);
    const selectFile = useDemoStore((state) => state.selectFile);
    const submitParse = useDemoStore((state) => state.submitParse);
    const submitDisabled = useDemoStore(selectIsSubmitDisabled);

    useEffect(() => {
        fetchStatus();
    }, [fetchStatus]);

    const handleFileChange = (event: React.ChangeEvent<HTMLInputElement>) => {
        selectFile(event.target.files?.[0] ?? null);
    };

    const handleSubmit = async (event: React.FormEvent) => {
        event.preventDefault();

        const success = await submitParse();

        if (success && fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    return (
        <>
            <Head title="CV Parser Demo" />
            <div className="min-h-screen bg-background p-6 md:p-10">
                <div className="mx-auto flex w-full max-w-3xl flex-col gap-6">
                    <div className="space-y-2">
                        <h1 className="text-3xl font-semibold tracking-tight">
                            CV Parser Demo
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Upload a PDF resume to extract structured JSON via
                            OpenRouter.
                        </p>
                    </div>

                    {!statusLoading && statusWarning && (
                        <Alert variant="destructive">
                            <AlertCircleIcon />
                            <AlertTitle>Parser unavailable</AlertTitle>
                            <AlertDescription>{statusWarning}</AlertDescription>
                        </Alert>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle>Upload PDF</CardTitle>
                            <CardDescription>
                                PDF only, up to 5 MB. Parsing may take up to 60
                                seconds.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                onSubmit={handleSubmit}
                                className="flex flex-col gap-4"
                            >
                                <div className="grid gap-2">
                                    <Label htmlFor="cv">Resume file</Label>
                                    <Input
                                        ref={fileInputRef}
                                        id="cv"
                                        type="file"
                                        accept="application/pdf"
                                        onChange={handleFileChange}
                                        disabled={
                                            statusLoading ||
                                            !statusAvailable ||
                                            uiState === 'processing'
                                        }
                                    />
                                </div>

                                <div className="flex items-center gap-3">
                                    <Button
                                        type="submit"
                                        disabled={submitDisabled}
                                    >
                                        {uiState === 'processing' ? (
                                            <>
                                                <Spinner />
                                                Parsing...
                                            </>
                                        ) : (
                                            <>
                                                <FileTextIcon />
                                                Parse CV
                                            </>
                                        )}
                                    </Button>

                                    {selectedFile &&
                                        uiState !== 'processing' && (
                                            <span className="text-sm text-muted-foreground">
                                                {selectedFile.name}
                                            </span>
                                        )}
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    {uiState === 'error' && errorMessage && (
                        <Alert variant="destructive">
                            <AlertCircleIcon />
                            <AlertTitle>Parse failed</AlertTitle>
                            <AlertDescription>{errorMessage}</AlertDescription>
                        </Alert>
                    )}

                    {uiState === 'success' && result && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Parsed result</CardTitle>
                                <CardDescription>
                                    Structured JSON grouped by personal info,
                                    experience & education, and skills &
                                    portfolio.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <pre className="overflow-x-auto rounded-lg bg-muted p-4 text-xs leading-relaxed">
                                    {JSON.stringify(result, null, 2)}
                                </pre>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </>
    );
}
