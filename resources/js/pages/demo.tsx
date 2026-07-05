import { Head } from '@inertiajs/react';
import { AlertCircleIcon, FileTextIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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
import { fetchParserStatus, parseCv } from '@/lib/parse-cv';
import type { ParseCvData } from '@/types/cv-parser';

type UiState = 'idle' | 'processing' | 'success' | 'error';

export default function Demo() {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [statusAvailable, setStatusAvailable] = useState(false);
    const [statusWarning, setStatusWarning] = useState<string | null>(null);
    const [statusLoading, setStatusLoading] = useState(true);
    const [uiState, setUiState] = useState<UiState>('idle');
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [result, setResult] = useState<ParseCvData | null>(null);

    useEffect(() => {
        fetchParserStatus()
            .then((status) => {
                setStatusAvailable(status.available);
                setStatusWarning(status.warning);
            })
            .catch(() => {
                setStatusAvailable(false);
                setStatusWarning('Unable to check parser status.');
            })
            .finally(() => {
                setStatusLoading(false);
            });
    }, []);

    const handleFileChange = (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0] ?? null;
        setSelectedFile(file);
        setErrorMessage(null);
        setResult(null);
        setUiState('idle');
    };

    const handleSubmit = async (event: React.FormEvent) => {
        event.preventDefault();

        if (!selectedFile || !statusAvailable || uiState === 'processing') {
            return;
        }

        setUiState('processing');
        setErrorMessage(null);
        setResult(null);

        try {
            const response = await parseCv(selectedFile);
            setResult(response.data);
            setSelectedFile(null);

            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }

            setUiState('success');
        } catch (error) {
            setUiState('error');
            setErrorMessage(
                error instanceof Error
                    ? error.message
                    : 'An unexpected error occurred.',
            );
        }
    };

    const submitDisabled =
        statusLoading ||
        !statusAvailable ||
        !selectedFile ||
        uiState === 'processing';

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
