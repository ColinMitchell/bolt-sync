import { Flex, FlexItem, FlexBlock, Spinner, __experimentalText as Text } from '@wordpress/components';

export default function LoadingFallback() {
    return (
        <Flex>
            <FlexItem>
                <Spinner />
            </FlexItem>
            <FlexBlock>
                <Text>Loading Post Link Data...</Text>
            </FlexBlock>
        </Flex>
    );
}