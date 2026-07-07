export async function runWithConcurrency<T>(
    items: T[],
    limit: number,
    worker: (item: T) => Promise<void>,
) {
    let nextIndex = 0;

    const workers = Array.from(
        { length: Math.min(limit, items.length) },
        async () => {
            while (nextIndex < items.length) {
                const item = items[nextIndex];
                nextIndex++;

                await worker(item);
            }
        },
    );

    await Promise.all(workers);
}
